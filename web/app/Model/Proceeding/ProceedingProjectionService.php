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

        foreach ($this->events->findByCaseFileAndSource((int) $proceeding->id, self::Source) as $event) {
            if ($event->refRegistryNorm !== null || $event->eventCode !== $code) {
                continue;
            }
            if ($event->eventDate?->format('Y-m-d') !== $date->format('Y-m-d')) {
                continue;
            }
            // Never replace a detail fetched individually more recently than
            // this overview snapshot.
            if ($event->detailFetchedAt !== null && $event->detailFetchedAt >= $fetchedAt) {
                return;
            }
            $changes = new CaseFileEvent;
            $changes->detailJson = Json::encode($detail);
            $changes->detailFetchedAt = \DateTimeImmutable::createFromInterface($fetchedAt);
            $this->events->update($event->id, $changes);
            return;
        }
    }


    /**
     * Drops and rebuilds the whole event memory of the case (used after a
     * detected data-integrity break, when pairing is meaningless).
     */
    public function resetInfosoudEvents(ActiveRow $proceeding): void
    {
        foreach ($this->events->findByCaseFileAndSource((int) $proceeding->id, self::Source) as $event) {
            $this->events->delete($event->id);
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
            $projected = new CaseFileEvent;
            $projected->eventCode = $code;
            $projected->eventOrder = isset($event['poradi']) ? (int) $event['poradi'] : null;
            $projected->upstreamId = ($event['udalostId'] ?? null) !== null ? (string) $event['udalostId'] : null;
            $projected->eventDate = self::normalizedEventDate($event['datum'] ?? null);
            $projected->cancelled = (bool) ($event['zruseno'] ?? false);
            $this->applyOwnerRef($projected, $proceeding, is_array($event['znackaId'] ?? null) ? $event['znackaId'] : []);
            // The pairing key covers the full owner identity - one case can
            // carry many foreign events of the same code AND poradi differing
            // only in the target case (NC 3601: 8x ODVOLANI, all poradi 1) -
            // and both sides derive it from the entity, so they cannot drift.
            $incoming[$projected->pairingKey()] = $projected;
        }

        $existing = [];
        foreach ($this->events->findByCaseFileAndSource((int) $proceeding->id, self::Source) as $event) {
            $existing[$event->pairingKey()] = $event;
        }

        foreach ($incoming as $key => $projected) {
            $current = $existing[$key] ?? null;
            if ($current === null) {
                $projected->caseFileId = (int) $proceeding->id;
                $projected->source = self::Source;
                $this->events->insert($projected);
                continue;
            }
            unset($existing[$key]);
            // A patch entity cannot be asked what is set on it (see
            // github.com/jakubboucek/hydrator#1), so the flag tracks it.
            $changes = new CaseFileEvent;
            $changed = false;
            if ($current->eventDate?->format('Y-m-d') !== $projected->eventDate?->format('Y-m-d')) {
                // A moved date on the same (code, order) smells like upstream
                // renumbering - the cached detail may belong to another event.
                $changes->eventDate = $projected->eventDate;
                $changes->detailJson = null;
                $changes->detailFetchedAt = null;
                $changed = true;
            }
            if ($current->cancelled !== $projected->cancelled) {
                $changes->cancelled = $projected->cancelled;
                $changed = true;
            }
            if ($current->upstreamId !== $projected->upstreamId) {
                $changes->upstreamId = $projected->upstreamId;
                $changed = true;
            }
            if ($changed) {
                $this->events->update($current->id, $changes);
            }
        }

        foreach ($existing as $event) {
            $this->events->delete($event->id);
        }
    }


    /**
     * Upstream event date as a typed value. The changed-date detection above
     * compares it against the stored DATE column - and a moved date
     * deliberately DROPS the cached detail (renumbering suspicion), so the
     * comparison must depend on the date itself, never on its formatting.
     * Both sides are DateTimeImmutable now, compared as Y-m-d.
     *
     * An unparseable token yields NULL, i.e. an event without a date - the
     * timeline already has a place for those. (It used to be stored verbatim
     * so the comparison degraded to byte equality; a typed column cannot hold
     * a non-date, and upstream has never sent one.)
     */
    private static function normalizedEventDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($raw));
        } catch (\Exception) {
            return null;
        }
    }


    /**
     * Fills the owner-case properties of an event: left NULL for the case's
     * own events, the znackaId identity for foreign ones (appeals etc.).
     *
     * @param array<mixed> $znackaId
     */
    private function applyOwnerRef(CaseFileEvent $event, ActiveRow $proceeding, array $znackaId): void
    {
        $event->refCourtKod = null;
        $event->refRegistryNorm = null;
        $event->refSenate = null;
        $event->refBcNumber = null;
        $event->refYear = null;

        if ($znackaId === []) {
            return;
        }
        if ($this->ownership->isOwn(
            $znackaId,
            (string) $proceeding->court_kod,
            (int) $proceeding->senate,
            (string) $proceeding->registry_norm,
            (int) $proceeding->bc_number,
            (int) $proceeding->year,
        )) {
            return;
        }
        $resolvedKod = $this->courtCodes->resolveKod((string) ($znackaId['organizace'] ?? ''));
        $senate = (int) ($znackaId['cisloSenatu'] ?? -1);
        $rawKod = (string) ($znackaId['organizace'] ?? '');
        $event->refCourtKod = $resolvedKod ?? ($rawKod !== '' ? $rawKod : null);
        $event->refRegistryNorm = strtoupper((string) ($znackaId['druhVeci'] ?? ''));
        $event->refSenate = max($senate, 0);
        $event->refBcNumber = (int) ($znackaId['bcVec'] ?? 0);
        $event->refYear = CaseYear::fromUpstream((int) ($znackaId['rocnik'] ?? 0));
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
            // The owner reference is computed on a throwaway event entity -
            // relations and the event projection must read znackaId alike.
            $ref = new CaseFileEvent;
            $this->applyOwnerRef($ref, $proceeding, is_array($event['znackaId'] ?? null) ? $event['znackaId'] : []);
            if (!$ref->isForeign()) {
                continue;
            }
            $add(
                $targets,
                $ref->refCourtKod,
                (string) $ref->refRegistryNorm,
                (int) $ref->refSenate,
                (int) $ref->refBcNumber,
                (int) $ref->refYear,
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
