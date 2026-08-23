<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Codelist\CourtCodeResolver;
use App\Model\Codelist\RelationType;
use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Infosoud\InfosoudOwnershipResolver;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use Nette\Database\Explorer;
use Nette\Utils\Json;


/**
 * Projects the raw per-source JSON of a case file into the derived tables
 * case_file_event and case_file_relation (see docs/analyza-udalosti.md).
 *
 * The run is split into plan() - a read-only diff of the stored state against
 * the fresh payload - and apply(), which writes exactly what the plan says.
 * The split serves the data-loss journal (a destructive plan is recorded with
 * before/after snapshots, see CaseFileJournalService) and future repair
 * tooling (a dry run is the plan printed instead of applied).
 *
 * Events pair by (source, event_code, event_order, owner ref) so that
 * surrogate ids - and therefore event URLs - survive an ordinary refresh.
 * A changed event date on a paired row drops its cached detail (renumbering
 * suspicion). Rows missing from the fresh timeline are deleted. Relations
 * pair on their identity; rows present on both sides survive untouched.
 */
final readonly class CaseFileProjectionService
{
    private const string Source = DataSource::Infosoud->value;

    public function __construct(
        private Explorer $db,
        private CaseFileEventRepository $events,
        private CaseFileRelationRepository $relations,
        private CaseFileJournalService $journal,
        private CourtCodeResolver $courtCodes,
        private InfosoudOwnershipResolver $ownership,
        private SpisovkaParser $parser,
        private CaseFileRepository $caseFiles,
    ) {
    }


    /**
     * Rebuilds both projections from the stored infosoud JSON of the row.
     * A destructive rebuild is recorded in the journal - reprojection never
     * touches the case_file row itself, so the before state can be captured
     * right here. (The live sync cannot use this method: it rewrites the raw
     * JSON first and orchestrates plan/apply/journal itself.)
     *
     * @throws StoredJsonException stored payload unreadable (data integrity)
     */
    public function projectInfosoud(CaseFile $caseFile): void
    {
        if ($caseFile->infosoudJson === null) {
            return;
        }
        // A damaged payload must not pass as "nothing to project" - that would
        // silently freeze the derived tables on their previous content. It is
        // journaled before rethrowing: the projection stays frozen and a later
        // repair may overwrite the broken payload, so the state is recorded
        // while it still exists.
        try {
            $case = StoredJson::decode($caseFile->infosoudJson, "case file #{$caseFile->id} (infosoud_json)");
        } catch (StoredJsonException $e) {
            $this->journal->recordPayloadUnreadable($caseFile, $e->getMessage());
            throw $e;
        }

        $this->db->getConnection()->transaction(function () use ($caseFile, $case): void {
            $plan = $this->plan($caseFile, $case);
            $before = $plan->isDestructive() ? $this->journal->captureState($caseFile) : null;
            $this->apply($caseFile, $case, $plan);
            if ($before !== null) {
                $this->journal->recordProjectionLoss($caseFile, $plan, $before, new \DateTimeImmutable);
            }
        });
    }


    /**
     * Read-only diff of the stored projection against the fresh payload.
     * $caseFile may be an unsaved identity-only entity (a case seen for the
     * first time) - the plan is then pure inserts.
     *
     * @param array<mixed> $case decoded infosoud payload of the case
     */
    public function plan(CaseFile $caseFile, array $case): CaseFileProjectionPlan
    {
        $udalosti = is_array($case['udalosti'] ?? null) ? $case['udalosti'] : [];
        // Which case each event belongs to is resolved once for both consumers
        // below: the event rows and the relations they imply must never read
        // znackaId differently.
        $ownerRefs = $this->resolveOwnerRefs($caseFile, $udalosti);

        return CaseFileProjectionPlan::diff(
            isset($caseFile->id) ? $this->events->findByCaseFileAndSource($caseFile->id, self::Source) : [],
            $this->projectEvents($udalosti, $ownerRefs),
            array_values(array_filter(
                $this->relations->findBySrc(
                    $caseFile->courtKod,
                    $caseFile->registryNorm,
                    $caseFile->senate,
                    $caseFile->bcNumber,
                    $caseFile->year,
                ),
                static fn(CaseFileRelation $relation): bool => $relation->source === self::Source,
            )),
            $this->projectRelations($caseFile, $case, $udalosti, $ownerRefs),
        );
    }


    /**
     * Writes exactly what the plan says. The caller owns the transaction -
     * and when the plan is destructive, also the journal entry (see
     * CaseFileJournalService::recordProjectionLoss()).
     *
     * @param array<mixed> $case decoded infosoud payload (for the first-event detail seed)
     */
    public function apply(CaseFile $caseFile, array $case, CaseFileProjectionPlan $plan): void
    {
        foreach ($plan->eventInserts as $event) {
            $event->caseFileId = $caseFile->id;
            $event->source = self::Source;
            $this->events->insert($event);
        }
        foreach ($plan->eventUpdates as $update) {
            $this->events->update($update->current->id, $update->changes);
        }
        foreach ($plan->eventDeletes as $event) {
            $this->events->delete($event->id);
        }
        $this->seedFirstEventDetail($caseFile, $case);
        foreach ($plan->relationDeletes as $relation) {
            $this->relations->delete($relation->id);
        }
        foreach ($plan->relationInserts as $relation) {
            $this->relations->insert($relation);
        }
    }


    /**
     * The case sync fetches the first own event's detail along with the
     * overview (firstEventDetail in the raw JSON) - propagate it into the
     * matching event row so the detail page never re-fetches it. The response
     * carries no poradi, so the row is matched by (own, code, date).
     *
     * @param array<mixed> $case
     */
    private function seedFirstEventDetail(CaseFile $caseFile, array $case): void
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
        $fetchedAt = $caseFile->infosoudAt ?? new \DateTimeImmutable;

        foreach ($this->events->findByCaseFileAndSource($caseFile->id, self::Source) as $event) {
            if ($event->refRegistryNorm !== null || $event->eventCode !== $code) {
                continue;
            }
            if ($event->eventDate?->format('Y-m-d') !== $date->format('Y-m-d')) {
                continue;
            }
            // Never replace a detail fetched individually more recently than
            // this overview snapshot. (Replacing an older one with the fresh
            // overview is an ordinary refresh of the same record, not a data
            // loss - the journal stays out of it.)
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
     *
     * NOT WIRED UP ON PURPOSE, and not dead code: hearings are bound to these
     * rows, so throwing the projection away needs a non-destructive path
     * first. The intent is recorded in docs/roadmap.md (*Nedestruktivní obnova
     * integrity událostí*) and docs/analyza-udalosti.md - keep it. Whoever
     * wires it up must also journal the wipe: the deletes happen outside the
     * plan, so projectInfosoud() below sees an empty state and records
     * nothing.
     */
    public function resetInfosoudEvents(CaseFile $caseFile): void
    {
        foreach ($this->events->findByCaseFileAndSource($caseFile->id, self::Source) as $event) {
            $this->events->delete($event->id);
        }
        $this->projectInfosoud($caseFile);
    }


    /**
     * Owner-case reference of every upstream event, keyed by its position in
     * the timeline array; the values are throwaway entities carrying nothing
     * but the ref* properties.
     *
     * @param array<mixed> $udalosti
     * @return array<int|string, CaseFileEvent>
     */
    private function resolveOwnerRefs(CaseFile $caseFile, array $udalosti): array
    {
        $refs = [];
        foreach ($udalosti as $index => $event) {
            if (!is_array($event)) {
                continue;
            }
            $ref = new CaseFileEvent;
            $this->applyOwnerRef($ref, $caseFile, is_array($event['znackaId'] ?? null) ? $event['znackaId'] : []);
            $refs[$index] = $ref;
        }
        return $refs;
    }


    /**
     * Event rows as the fresh payload describes them, deduplicated by the
     * pairing key (a later duplicate wins, as it always has).
     *
     * @param array<mixed> $udalosti
     * @param array<int|string, CaseFileEvent> $ownerRefs keyed like $udalosti
     * @return list<CaseFileEvent>
     */
    private function projectEvents(array $udalosti, array $ownerRefs): array
    {
        $incoming = [];
        foreach ($udalosti as $index => $event) {
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
            $projected->takeOwnerRefFrom($ownerRefs[$index]);
            // The pairing key covers the full owner identity - one case can
            // carry many foreign events of the same code AND poradi differing
            // only in the target case (NC 3601: 8x ODVOLANI, all poradi 1) -
            // and both sides derive it from the entity, so they cannot drift.
            $incoming[$projected->pairingKey()] = $projected;
        }
        return array_values($incoming);
    }


    /**
     * Upstream event date as a typed value. The changed-date detection in the
     * plan compares it against the stored DATE column - and a moved date
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
    private function applyOwnerRef(CaseFileEvent $event, CaseFile $caseFile, array $znackaId): void
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
            $caseFile->courtKod,
            $caseFile->senate,
            $caseFile->registryNorm,
            $caseFile->bcNumber,
            $caseFile->year,
        )) {
            return;
        }
        $resolvedKod = $this->courtCodes->resolveKod((string) ($znackaId['organizace'] ?? ''));
        $senate = (int) ($znackaId['cisloSenatu'] ?? -1);
        $rawKod = (string) ($znackaId['organizace'] ?? '');
        $event->refCourtKod = $resolvedKod ?? ($rawKod !== '' ? $rawKod : null);
        $event->refRegistryNorm = mb_strtoupper((string) ($znackaId['druhVeci'] ?? ''));
        $event->refSenate = max($senate, 0);
        $event->refBcNumber = (int) ($znackaId['bcVec'] ?? 0);
        $event->refYear = CaseYear::fromUpstream((int) ($znackaId['rocnik'] ?? 0));
    }


    /**
     * Relation rows as the fresh payload describes them, deduplicated by
     * their identity and fully stamped (src side + data source).
     *
     * @param array<mixed> $case
     * @param array<mixed> $udalosti timeline of $case, already extracted
     * @param array<int|string, CaseFileEvent> $ownerRefs keyed like $udalosti
     * @return list<CaseFileRelation>
     */
    private function projectRelations(CaseFile $caseFile, array $case, array $udalosti, array $ownerRefs): array
    {
        $targets = [];
        // Collects the target side of each relation; the source side and the
        // data source are stamped on below.
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
        foreach ($udalosti as $index => $event) {
            if (!is_array($event)) {
                continue;
            }
            // Same reference the event projection used - resolved once above.
            $ref = $ownerRefs[$index];
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
                mb_strtoupper((string) ($ref['druhVeci'] ?? $ref['druh'] ?? '')),
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
                $known = $this->caseFiles->findBySpisovka($parsed);
                $courtKod = count($known) === 1 ? $known[0]->courtKod : null;
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

        foreach ($targets as $relation) {
            $relation->srcCourtKod = $caseFile->courtKod;
            $relation->srcRegistryNorm = $caseFile->registryNorm;
            $relation->srcSenate = $caseFile->senate;
            $relation->srcBcNumber = $caseFile->bcNumber;
            $relation->srcYear = $caseFile->year;
            $relation->source = self::Source;
        }
        return array_values($targets);
    }
}
