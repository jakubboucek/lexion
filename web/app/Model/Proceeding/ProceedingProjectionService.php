<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Codelist\CourtCodeResolver;
use App\Model\Codelist\RegistryRepository;
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
    private const string Source = 'infosoud';

    public function __construct(
        private Explorer $explorer,
        private ProceedingEventRepository $events,
        private ProceedingRelationRepository $relations,
        private CourtCodeResolver $courtCodes,
        private SpisovkaParser $parser,
        private RegistryRepository $registries,
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

        $this->explorer->getConnection()->transaction(function () use ($proceeding, $case): void {
            $this->syncEvents($proceeding, is_array($case['udalosti'] ?? null) ? $case['udalosti'] : []);
            $this->syncRelations($proceeding, $case);
        });
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
                'event_date' => ($event['datum'] ?? null) !== null ? (string) $event['datum'] : null,
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
        // Org codes may be infosoud-internal aliases (NSJIMBM = NS); NS events
        // carry senate 0 instead of the real senate number.
        $resolvedKod = $this->courtCodes->resolveKod((string) ($znackaId['organizace'] ?? ''));
        $senate = (int) ($znackaId['cisloSenatu'] ?? -1);
        $isOwn = $resolvedKod === (string) $proceeding->court_kod
            && ($senate === (int) $proceeding->senate || $senate === 0)
            && strtoupper((string) ($znackaId['druhVeci'] ?? '')) === (string) $proceeding->registry_norm
            && (int) ($znackaId['bcVec'] ?? -1) === (int) $proceeding->bc_number
            && (int) ($znackaId['rocnik'] ?? -1) === (int) $proceeding->year;
        if ($isOwn) {
            return $own;
        }
        $rawKod = (string) ($znackaId['organizace'] ?? '');
        return [
            'ref_court_kod' => $resolvedKod ?? ($rawKod !== '' ? $rawKod : null),
            'ref_registry_norm' => strtoupper((string) ($znackaId['druhVeci'] ?? '')),
            'ref_senate' => max($senate, 0),
            'ref_bc_number' => (int) ($znackaId['bcVec'] ?? 0),
            'ref_year' => (int) ($znackaId['rocnik'] ?? 0),
        ];
    }


    /** @param array<mixed> $case */
    private function syncRelations(ActiveRow $proceeding, array $case): void
    {
        $targets = [];
        $add = static function (array &$targets, ?string $courtKod, string $registryNorm, int $senate, int $bcNumber, int $year, string $type): void {
            if ($registryNorm === '' || $bcNumber === 0 || $year === 0) {
                return;
            }
            $key = ($courtKod ?? '') . '|' . $registryNorm . '|' . $senate . '|' . $bcNumber . '|' . $year . '|' . $type;
            $targets[$key] = [
                'dst_court_kod' => $courtKod,
                'dst_registry_norm' => $registryNorm,
                'dst_senate' => $senate,
                'dst_bc_number' => $bcNumber,
                'dst_year' => $year,
                'relation_type' => $type,
            ];
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
            $type = match ((string) ($event['udalost'] ?? '')) {
                'ODVOLANI' => 'ODVOLANI',
                'NAD_RIZENI' => 'NAD_RIZENI',
                'DOVOL_RIZ' => 'DOVOL_RIZ',
                'PREVD_SPIS' => 'PREVD_SPIS',
                default => 'SOUVISEJICI',
            };
            $add(
                $targets,
                $ref['ref_court_kod'],
                (string) $ref['ref_registry_norm'],
                (int) $ref['ref_senate'],
                (int) $ref['ref_bc_number'],
                (int) $ref['ref_year'],
                $type,
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
                (int) ($ref['rocnik'] ?? 0),
                'NAVAZNA_VEC',
            );
        }

        // 3. PRED_VEC attribute of the first event detail (carries no court).
        $attributes = [];
        foreach (is_array($case['firstEventDetail']['atributy'] ?? null) ? $case['firstEventDetail']['atributy'] : [] as $attribute) {
            if (is_array($attribute) && isset($attribute['typ'])) {
                $attributes[(string) $attribute['typ']] = (string) ($attribute['hodnota'] ?? '');
            }
        }
        if (($attributes['PRED_VEC'] ?? '-') !== '-') {
            try {
                $parsed = $this->parser->parse($attributes['PRED_VEC']);
                // For appeals PRED_VEC points at the subordinate court, so
                // prefer a unique cache match across courts before falling
                // back to "same court". An unknown registry means the
                // reference is not a court case at all (e.g. a prosecutor
                // file) - no court guess then.
                $registryKnown = $this->registries->displayFromNorm($parsed->registryNorm()) !== null;
                $cachedRows = $this->proceedings->findBySpisovka(
                    $parsed->registryNorm(),
                    $parsed->senate,
                    $parsed->number,
                    $parsed->year,
                );
                $courtKod = count($cachedRows) === 1
                    ? (string) $cachedRows[0]->court_kod
                    : ($registryKnown ? (string) $proceeding->court_kod : null);
                $add(
                    $targets,
                    $courtKod,
                    $parsed->registryNorm(),
                    $parsed->senate,
                    $parsed->number,
                    $parsed->year,
                    'PRED_VEC',
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
        foreach ($targets as $target) {
            $this->relations->insert($target + [
                'src_court_kod' => (string) $proceeding->court_kod,
                'src_registry_norm' => (string) $proceeding->registry_norm,
                'src_senate' => (int) $proceeding->senate,
                'src_bc_number' => (int) $proceeding->bc_number,
                'src_year' => (int) $proceeding->year,
                'source' => self::Source,
            ]);
        }
    }
}
