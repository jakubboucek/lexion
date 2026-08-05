<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Codelist\CourtCodeResolver;
use App\Model\Codelist\RelationType;
use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Infosoud\InfosoudOwnershipResolver;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;


/**
 * Projects the raw per-source JSON of a proceeding into the derived tables
 * proceeding_event and proceeding_relation (see docs/analyza-udalosti.md).
 *
 * Events sync is an upsert paired by (source, event_code, event_order, owner
 * ref) so that surrogate ids - and therefore event URLs - survive an ordinary
 * refresh. A changed event date on a paired row drops its cached detail
 * (renumbering suspicion). Rows missing from the fresh timeline are deleted.
 *
 * Relations sync is a plain rebuild of the case's rows with the matching
 * source; manual relations are never touched.
 */
final readonly class ProceedingProjectionService
{
    private const string Source = DataSource::Infosoud->value;

    public function __construct(
        private Explorer $db,
        private ProceedingEventRepository $events,
        private ProceedingRelationRepository $relations,
        private CourtCodeResolver $courtCodes,
        private InfosoudOwnershipResolver $ownership,
        private SpisovkaParser $parser,
        private ProceedingRepository $proceedings,
    ) {
    }


    /** Rebuilds both projections from the stored infosoud JSON of the row. */
    public function projectInfosoud(ActiveRow $proceeding): void
    {
        if ($proceeding->infosoud_json === null) {
            return;
        }
        $case = Json::decode((string) $proceeding->infosoud_json, forceArrays: true);
        if (!is_array($case)) {
            return;
        }

        $this->db->getConnection()->transaction(function () use ($proceeding, $case): void {
            $this->syncEvents($proceeding, is_array($case['udalosti'] ?? null) ? $case['udalosti'] : []);
            $this->seedFirstEventDetail($proceeding, $case);
            $this->syncRelations($proceeding, $case);
        });
    }


    /**
     * The case sync fetches the first own event's detail along with the
     * overview (firstEventDetail in the raw JSON) - propagate it into the
     * matching event row so the detail page never re-fetches it. The response
     * carries no poradi, so the row is matched by (own, code, date).
     *
     * @param array<mixed> $case
     */
    private function seedFirstEventDetail(ActiveRow $proceeding, array $case): void
    {
        $detail = $case['firstEventDetail'] ?? null;
        if (!is_array($detail)) {
            return;
        }
        $code = (string) ($detail['typUdalosti'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', (string) ($detail['datumUdalost'] ?? ''));
        if ($code === '' || $date === false) {
            return;
        }
        $fetchedAt = $proceeding->infosoud_at instanceof \DateTimeInterface
            ? $proceeding->infosoud_at
            : new \DateTimeImmutable;

        foreach ($this->events->findByProceedingAndSource((int) $proceeding->id, self::Source) as $row) {
            if ($row->ref_registry_norm !== null || (string) $row->event_code !== $code) {
                continue;
            }
            $rowDate = $row->event_date instanceof \DateTimeInterface ? $row->event_date->format('Y-m-d') : null;
            if ($rowDate !== $date->format('Y-m-d')) {
                continue;
            }
            // Never replace a detail fetched individually more recently than
            // this overview snapshot.
            if ($row->detail_fetched_at instanceof \DateTimeInterface && $row->detail_fetched_at >= $fetchedAt) {
                return;
            }
            $this->events->update((int) $row->id, [
                'detail_json' => Json::encode($detail),
                'detail_fetched_at' => $fetchedAt,
            ]);
            return;
        }
    }


    /**
     * Drops and rebuilds the whole event memory of the case (used after a
     * detected data-integrity break, when pairing is meaningless).
     */
    public function resetInfosoudEvents(ActiveRow $proceeding): void
    {
        foreach ($this->events->findByProceedingAndSource((int) $proceeding->id, self::Source) as $row) {
            $this->events->delete((int) $row->id);
        }
        $this->projectInfosoud($proceeding);
    }


    /** @param array<mixed> $udalosti */
    private function syncEvents(ActiveRow $proceeding, array $udalosti): void
    {
        $incoming = [];
        foreach ($udalosti as $event) {
            if (!is_array($event)) {
                continue;
            }
            $code = (string) ($event['udalost'] ?? '');
            if ($code === '') {
                continue;
            }
            $ref = $this->ownerRef($proceeding, is_array($event['znackaId'] ?? null) ? $event['znackaId'] : []);
            $order = isset($event['poradi']) ? (int) $event['poradi'] : null;
            // The full owner identity belongs in the pairing key: one case can
            // carry many foreign events of the same code AND poradi differing
            // only in the target case (NC 3601: 8x ODVOLANI, all poradi 1).
            $key = implode('|', [$code, $order ?? '', ...array_values($ref)]);
            $incoming[$key] = $ref + [
                'event_code' => $code,
                'event_order' => $order,
                'upstream_id' => ($event['udalostId'] ?? null) !== null ? (string) $event['udalostId'] : null,
                'event_date' => self::normalizedEventDate($event['datum'] ?? null),
                'cancelled' => (bool) ($event['zruseno'] ?? false),
            ];
        }

        $existing = [];
        foreach ($this->events->findByProceedingAndSource((int) $proceeding->id, self::Source) as $row) {
            $key = implode('|', [
                (string) $row->event_code,
                $row->event_order ?? '',
                $row->ref_court_kod ?? '',
                $row->ref_registry_norm ?? '',
                $row->ref_senate ?? '',
                $row->ref_bc_number ?? '',
                $row->ref_year ?? '',
            ]);
            $existing[$key] = $row;
        }

        foreach ($incoming as $key => $data) {
            $row = $existing[$key] ?? null;
            if ($row === null) {
                $this->events->insert($data + [
                    'proceeding_id' => (int) $proceeding->id,
                    'source' => self::Source,
                ]);
                continue;
            }
            unset($existing[$key]);
            $changes = [];
            $rowDate = $row->event_date instanceof \DateTimeInterface ? $row->event_date->format('Y-m-d') : null;
            if ($rowDate !== $data['event_date']) {
                // A moved date on the same (code, order) smells like upstream
                // renumbering - the cached detail may belong to another event.
                $changes = ['event_date' => $data['event_date'], 'detail_json' => null, 'detail_fetched_at' => null];
            }
            if ((bool) $row->cancelled !== $data['cancelled']) {
                $changes['cancelled'] = $data['cancelled'];
            }
            if ($row->upstream_id !== $data['upstream_id']) {
                $changes['upstream_id'] = $data['upstream_id'];
            }
            if ($changes !== []) {
                $this->events->update((int) $row->id, $changes);
            }
        }

        foreach ($existing as $row) {
            $this->events->delete((int) $row->id);
        }
    }


    /**
     * Upstream event date canonicalized to Y-m-d. The changed-date detection
     * below compares the incoming value against the DB DATE column formatted
     * as Y-m-d - and a moved date deliberately DROPS the cached detail
     * (renumbering suspicion). Comparing the raw upstream token against the
     * normalized DB value means any representation change (upstream adding
     * a time part, a future typed-hydration layer reshaping dates) would make
     * every event look changed and silently flush all cached details. With
     * both sides canonicalized the comparison depends on the date itself, not
     * on its formatting. An unparseable token is kept verbatim so the
     * comparison degrades to byte equality instead of guessing.
     */
    private static function normalizedEventDate(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($raw)))->format('Y-m-d');
        } catch (\Exception) {
            return trim($raw);
        }
    }


    /**
     * Owner-case columns of an event: NULL columns for the case's own events,
     * the znackaId identity for foreign ones (appeals etc.).
     *
     * @param array<mixed> $znackaId
     * @return array{ref_court_kod: ?string, ref_registry_norm: ?string, ref_senate: ?int, ref_bc_number: ?int, ref_year: ?int}
     */
    private function ownerRef(ActiveRow $proceeding, array $znackaId): array
    {
        $own = [
            'ref_court_kod' => null,
            'ref_registry_norm' => null,
            'ref_senate' => null,
            'ref_bc_number' => null,
            'ref_year' => null,
        ];
        if ($znackaId === []) {
            return $own;
        }
        if ($this->ownership->isOwn(
            $znackaId,
            (string) $proceeding->court_kod,
            (int) $proceeding->senate,
            (string) $proceeding->registry_norm,
            (int) $proceeding->bc_number,
            (int) $proceeding->year,
        )) {
            return $own;
        }
        $resolvedKod = $this->courtCodes->resolveKod((string) ($znackaId['organizace'] ?? ''));
        $senate = (int) ($znackaId['cisloSenatu'] ?? -1);
        $rawKod = (string) ($znackaId['organizace'] ?? '');
        return [
            'ref_court_kod' => $resolvedKod ?? ($rawKod !== '' ? $rawKod : null),
            'ref_registry_norm' => strtoupper((string) ($znackaId['druhVeci'] ?? '')),
            'ref_senate' => max($senate, 0),
            'ref_bc_number' => (int) ($znackaId['bcVec'] ?? 0),
            'ref_year' => CaseYear::fromUpstream((int) ($znackaId['rocnik'] ?? 0)),
        ];
    }


    /** @param array<mixed> $case */
    private function syncRelations(ActiveRow $proceeding, array $case): void
    {
        $targets = [];
        // Collects the target side of each relation; the source side and the
        // data source are stamped on when the rows are written below.
        $add = static function (array &$targets, ?string $courtKod, string $registryNorm, int $senate, int $bcNumber, int $year, string $type): void {
            if ($registryNorm === '' || $bcNumber === 0 || $year === 0) {
                return;
            }
            $key = ($courtKod ?? '') . '|' . $registryNorm . '|' . $senate . '|' . $bcNumber . '|' . $year . '|' . $type;
            $relation = new CaseFileRelation;
            $relation->dstCourtKod = $courtKod;
            $relation->dstRegistryNorm = $registryNorm;
            $relation->dstSenate = $senate;
            $relation->dstBcNumber = $bcNumber;
            $relation->dstYear = $year;
            $relation->relationType = $type;
            $targets[$key] = $relation;
        };

        // 1. Foreign events in the timeline (appeal cases etc.).
        foreach (is_array($case['udalosti'] ?? null) ? $case['udalosti'] : [] as $event) {
            if (!is_array($event)) {
                continue;
            }
            $ref = $this->ownerRef($proceeding, is_array($event['znackaId'] ?? null) ? $event['znackaId'] : []);
            if ($ref['ref_court_kod'] === null && $ref['ref_registry_norm'] === null) {
                continue;
            }
            $add(
                $targets,
                $ref['ref_court_kod'],
                (string) $ref['ref_registry_norm'],
                (int) $ref['ref_senate'],
                (int) $ref['ref_bc_number'],
                (int) $ref['ref_year'],
                RelationType::forEventCode((string) ($event['udalost'] ?? ''))->value,
            );
        }

        // 2. Case-level navazneVeci (one-way links, e.g. NC -> P a Nc).
        foreach (is_array($case['navazneVeci'] ?? null) ? $case['navazneVeci'] : [] as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            // Case-level items use cisloSenatu/druhVeci keys, event-detail
            // items use cislo/druh - accept both.
            $add(
                $targets,
                ($kod = (string) ($ref['organizace'] ?? '')) !== '' ? ($this->courtCodes->resolveKod($kod) ?? $kod) : null,
                strtoupper((string) ($ref['druhVeci'] ?? $ref['druh'] ?? '')),
                (int) ($ref['cisloSenatu'] ?? $ref['cislo'] ?? 0),
                (int) ($ref['bcVec'] ?? 0),
                CaseYear::fromUpstream((int) ($ref['rocnik'] ?? 0)),
                RelationType::NavaznaVec->value,
            );
        }

        // 3. PRED_VEC attribute of the first event detail (carries no court).
        $attributes = InfosoudEventAttribute::mapFromDetail(
            is_array($case['firstEventDetail'] ?? null) ? $case['firstEventDetail'] : [],
        );
        $predVec = $attributes['PRED_VEC'] ?? null;
        if ($predVec !== null) {
            try {
                $parsed = $this->parser->parse($predVec);
                // PRED_VEC carries no court and infosoud itself renders it as
                // plain text for that very reason, so the court is only filled
                // in when the cache identifies the case beyond doubt (exactly
                // one match across all courts). Defaulting to "the same court"
                // used to look right for a first-instance case converted from
                // an electronic payment order, but it is provably wrong for an
                // appeal: 12 Co 130/2019 at MS Praha then claimed its
                // predecessor 29 C 139/2017 was at MS Praha too, when an appeal
                // by definition reviews a subordinate court's case.
                $cachedRows = $this->proceedings->findBySpisovka($parsed);
                $courtKod = count($cachedRows) === 1 ? (string) $cachedRows[0]->court_kod : null;
                $add(
                    $targets,
                    $courtKod,
                    $parsed->registryNorm(),
                    $parsed->senate,
                    $parsed->number,
                    $parsed->year,
                    RelationType::PredVec->value,
                );
            } catch (SpisovkaParseException) {
                // unparseable references stay out - nothing to link to
            }
        }

        $this->relations->deleteBySrcAndSource(
            (string) $proceeding->court_kod,
            (string) $proceeding->registry_norm,
            (int) $proceeding->senate,
            (int) $proceeding->bc_number,
            (int) $proceeding->year,
            self::Source,
        );
        foreach ($targets as $relation) {
            $relation->srcCourtKod = (string) $proceeding->court_kod;
            $relation->srcRegistryNorm = (string) $proceeding->registry_norm;
            $relation->srcSenate = (int) $proceeding->senate;
            $relation->srcBcNumber = (int) $proceeding->bc_number;
            $relation->srcYear = (int) $proceeding->year;
            $relation->source = self::Source;
            $this->relations->insert($relation);
        }
    }
}
