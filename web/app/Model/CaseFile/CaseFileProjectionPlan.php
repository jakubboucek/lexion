<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * What one projection run intends to do to the derived tables, computed
 * before anything is written. The split exists for three consumers: the
 * projection itself (apply exactly this), the data-loss journal (is the run
 * destructive, and what precisely gets lost - see JournalEntryType) and
 * future repair tooling, whose dry run is this plan printed instead of
 * applied (docs/navrh-integrita-dat.md, step 4).
 *
 * The diff is pure - entities in, plan out, no I/O - so the renumbering
 * semantics ("a shifted poradi is a delete + insert, never an update") are
 * finally testable; see tests/Model/CaseFileProjectionPlan.phpt.
 */
final readonly class CaseFileProjectionPlan
{
    /**
     * @param list<CaseFileEvent> $eventInserts fresh rows (caseFileId/source stamped on apply)
     * @param list<CaseFileEventUpdate> $eventUpdates patches of paired rows
     * @param list<CaseFileEvent> $eventDeletes current rows missing from the fresh timeline
     * @param list<CaseFileRelation> $relationInserts fresh rows, fully stamped
     * @param list<CaseFileRelation> $relationDeletes current rows missing from the fresh payload
     */
    private function __construct(
        public array $eventInserts,
        public array $eventUpdates,
        public array $eventDeletes,
        public array $relationInserts,
        public array $relationDeletes,
    ) {
    }


    /**
     * Pairs the stored state with the incoming one. Events pair on
     * CaseFileEvent::pairingKey() - which contains the upstream poradi, so a
     * renumbered event does NOT pair: it shows up as a delete + insert, and
     * that is precisely the destruction the journal exists to record.
     * Relations pair on their identity key; rows present on both sides are
     * left untouched (their ids and created_at survive, unlike the former
     * delete-all-and-rebuild).
     *
     * @param list<CaseFileEvent> $existingEvents stored rows of the projected source
     * @param list<CaseFileEvent> $incomingEvents rows projected from the fresh payload
     * @param list<CaseFileRelation> $existingRelations stored rows of the projected source
     * @param list<CaseFileRelation> $incomingRelations rows projected from the fresh payload
     */
    public static function diff(
        array $existingEvents,
        array $incomingEvents,
        array $existingRelations,
        array $incomingRelations,
    ): self
    {
        $inserts = $updates = [];
        $remaining = [];
        foreach ($existingEvents as $event) {
            $remaining[$event->pairingKey()] = $event;
        }

        foreach ($incomingEvents as $projected) {
            $current = $remaining[$projected->pairingKey()] ?? null;
            if ($current === null) {
                $inserts[] = $projected;
                continue;
            }
            unset($remaining[$projected->pairingKey()]);
            // Collect only what actually differs; an untouched patch entity
            // carries nothing and the repository update() then does nothing.
            $changes = new CaseFileEvent;
            $dropsDetail = false;
            if ($current->eventDate?->format('Y-m-d') !== $projected->eventDate?->format('Y-m-d')) {
                // A moved date on the same (code, order) smells like upstream
                // renumbering - the cached detail may belong to another event.
                $changes->eventDate = $projected->eventDate;
                $changes->detailJson = null;
                $changes->detailFetchedAt = null;
                $dropsDetail = $current->detailJson !== null;
            }
            if ($current->cancelled !== $projected->cancelled) {
                $changes->cancelled = $projected->cancelled;
            }
            if ($current->upstreamId !== $projected->upstreamId) {
                $changes->upstreamId = $projected->upstreamId;
            }
            $updates[] = new CaseFileEventUpdate($current, $changes, $dropsDetail);
        }

        $relationInserts = [];
        $remainingRelations = [];
        foreach ($existingRelations as $relation) {
            $remainingRelations[self::relationKey($relation)] = $relation;
        }
        foreach ($incomingRelations as $relation) {
            $key = self::relationKey($relation);
            if (isset($remainingRelations[$key])) {
                unset($remainingRelations[$key]);
                continue;
            }
            $relationInserts[] = $relation;
        }

        return new self(
            $inserts,
            $updates,
            array_values($remaining),
            $relationInserts,
            array_values($remainingRelations),
        );
    }


    /**
     * Does applying this plan destroy anything? Any delete counts - an event
     * row's id is in URLs and hearings corroborate against its detail, a
     * relation row is knowledge the fresh payload no longer carries - and so
     * does wiping a fetched detail on a moved date.
     */
    public function isDestructive(): bool
    {
        if ($this->eventDeletes !== [] || $this->relationDeletes !== []) {
            return true;
        }
        foreach ($this->eventUpdates as $update) {
            if ($update->dropsDetail) {
                return true;
            }
        }
        return false;
    }


    /**
     * The destructive operations of the plan, as the journal's `context`
     * payload (JournalEntryType::ProjectionDataLoss). Facts only - ids and
     * identities of what gets lost; the full states live in the entry's
     * before/after snapshots.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function lossContext(): array
    {
        $context = [];
        foreach ($this->eventDeletes as $event) {
            $context['droppedEvents'][] = [
                'id' => $event->id,
                'eventCode' => $event->eventCode,
                'eventOrder' => $event->eventOrder,
                'eventDate' => $event->eventDate?->format('Y-m-d'),
                'foreign' => $event->isForeign(),
                'hadDetail' => $event->detailJson !== null,
            ];
        }
        foreach ($this->eventUpdates as $update) {
            if (!$update->dropsDetail) {
                continue;
            }
            $context['droppedDetails'][] = [
                'id' => $update->current->id,
                'eventCode' => $update->current->eventCode,
                'eventOrder' => $update->current->eventOrder,
                'dateBefore' => $update->current->eventDate?->format('Y-m-d'),
                'dateAfter' => $update->changes->eventDate?->format('Y-m-d'),
            ];
        }
        foreach ($this->relationDeletes as $relation) {
            $context['droppedRelations'][] = [
                'id' => $relation->id,
                'relationType' => $relation->relationType,
                'dstCourtKod' => $relation->dstCourtKod,
                'dst' => "{$relation->dstSenate} {$relation->dstRegistryNorm} {$relation->dstBcNumber}/{$relation->dstYear}",
            ];
        }
        return $context;
    }


    /**
     * Identity of a relation row within one source case - the same fields the
     * uq_case_file_relation key spans (minus the src side and source, which
     * are constant within one plan).
     */
    private static function relationKey(CaseFileRelation $relation): string
    {
        return implode('|', [
            $relation->dstCourtKod ?? '',
            $relation->dstRegistryNorm,
            $relation->dstSenate,
            $relation->dstBcNumber,
            $relation->dstYear,
            $relation->relationType,
        ]);
    }
}
