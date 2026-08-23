<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Codelist\Court;
use App\Model\Spisovka\Spisovka;
use JakubBoucek\Hydrator\Format;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use JakubBoucek\Hydrator\Struct\JsonObject;


/**
 * Writes the data-loss journal of case files (table `case_file_journal`, see
 * JournalEntryType). The journal records facts, never interpretations, and
 * only actual loss - a refresh that changes nothing writes nothing.
 *
 * Snapshots use the Hydrator Json format: field names are the entity property
 * names (the target domain naming, so a future DB rename never touches stored
 * snapshots) and the representation hydrates back into the same entities,
 * which is what makes a future restore tool possible without hand-written
 * SQL. Restoring is deliberately not implemented.
 *
 * A journal write belongs to the same transaction as the destruction it
 * records - the callers own the transaction. The exception is recording a
 * *refusal* (nothing was written), which has no transaction to join.
 */
final readonly class CaseFileJournalService
{
    /** @var Hydrator<CaseFile> */
    private Hydrator $caseFileHydrator;
    /** @var Hydrator<CaseFileEvent> */
    private Hydrator $eventHydrator;
    /** @var Hydrator<CaseFileRelation> */
    private Hydrator $relationHydrator;


    public function __construct(
        private CaseFileJournalRepository $journal,
        private CaseFileRepository $caseFiles,
        private CaseFileEventRepository $events,
        private CaseFileRelationRepository $relations,
        HydratorFactory $hydrators,
    ) {
        $this->caseFileHydrator = $hydrators->for(CaseFile::class, Format\Json::class);
        $this->eventHydrator = $hydrators->for(CaseFileEvent::class, Format\Json::class);
        $this->relationHydrator = $hydrators->for(CaseFileRelation::class, Format\Json::class);
    }


    /**
     * Full state of a case as a JSON snapshot: the case_file row, all its
     * event rows (every source) and all relation rows where it is the source
     * side (every data source incl. manual - completeness over minimalism).
     * Raw payload columns stay embedded as string values, byte-exact.
     */
    public function captureState(CaseFile $caseFile): JsonObject
    {
        $events = [];
        foreach ($this->events->findByCaseFile($caseFile->id) as $event) {
            $events[] = $this->eventHydrator->toData($event);
        }
        $relations = [];
        $stored = $this->relations->findBySrc(
            $caseFile->courtKod,
            $caseFile->registryNorm,
            $caseFile->senate,
            $caseFile->bcNumber,
            $caseFile->year,
        );
        foreach ($stored as $relation) {
            $relations[] = $this->relationHydrator->toData($relation);
        }
        return JsonObject::fromArray([
            'caseFile' => $this->caseFileHydrator->toData($caseFile),
            'caseFileEvents' => $events,
            'caseFileRelations' => $relations,
        ]);
    }


    /**
     * Records a destructive projection run. $stateBefore is captured by the
     * caller - only it knows the moment before its first write (the sync
     * rewrites the case_file row before the projection runs); the after state
     * is captured here, so call this once the plan is fully applied, within
     * the same transaction.
     */
    public function recordProjectionLoss(
        CaseFile $caseFile,
        CaseFileProjectionPlan $plan,
        JsonObject $stateBefore,
        \DateTimeImmutable $occurredAt,
    ): void
    {
        $this->insert(
            JournalEntryType::ProjectionDataLoss,
            $caseFile->id,
            $occurredAt,
            $stateBefore,
            $this->captureState($caseFile),
            $plan->lossContext(),
        );
    }


    /**
     * Records an event detail refused because it describes a different record
     * (EventDetailOutcome::IntegrityBroken). Nothing was written, so there is
     * no after state; the refused payload is the context's centerpiece - the
     * only authentic evidence of a renumbered case we will ever hold.
     *
     * @param array<mixed> $detail the refused upstream payload
     */
    public function recordDetailRejected(CaseFileEvent $event, array $detail): void
    {
        $caseFile = $this->caseFiles->findByIds([$event->caseFileId])[$event->caseFileId] ?? null;
        $this->insert(
            JournalEntryType::EventDetailRejected,
            $event->caseFileId,
            new \DateTimeImmutable,
            $caseFile !== null ? $this->captureState($caseFile) : new JsonObject,
            new JsonObject,
            [
                'event' => [
                    'id' => $event->id,
                    'eventCode' => $event->eventCode,
                    'eventOrder' => $event->eventOrder,
                    'eventDate' => $event->eventDate?->format('Y-m-d'),
                    'foreign' => $event->isForeign(),
                ],
                'received' => $detail,
            ],
        );
    }


    /**
     * Records an upstream case response refused instead of stored (a year
     * mismatch). $stored is NULL when we hold no row for the identity - the
     * entry then has no case_file_id and the payload in context is all there
     * is.
     *
     * @param array<mixed> $payload the refused upstream response
     */
    public function recordCaseResponseRejected(
        ?CaseFile $stored,
        Court $court,
        Spisovka $spisovka,
        array $payload,
    ): void
    {
        $this->insert(
            JournalEntryType::CaseResponseRejected,
            $stored?->id,
            new \DateTimeImmutable,
            $stored !== null ? $this->captureState($stored) : new JsonObject,
            new JsonObject,
            [
                'requested' => [
                    'courtKod' => $court->kod,
                    'registryNorm' => $spisovka->registryNorm(),
                    'senate' => $spisovka->senate,
                    'bcNumber' => $spisovka->number,
                    'year' => $spisovka->year,
                ],
                'received' => $payload,
            ],
        );
    }


    /** Records a stored payload that could not be decoded (StoredJsonException). */
    public function recordPayloadUnreadable(CaseFile $caseFile, string $error): void
    {
        $this->insert(
            JournalEntryType::PayloadUnreadable,
            $caseFile->id,
            new \DateTimeImmutable,
            $this->captureState($caseFile),
            new JsonObject,
            ['error' => $error],
        );
    }


    /**
     * @param array<mixed> $context
     */
    private function insert(
        JournalEntryType $type,
        ?int $caseFileId,
        \DateTimeImmutable $occurredAt,
        JsonObject $stateBefore,
        JsonObject $stateAfter,
        array $context,
    ): void
    {
        $entry = new CaseFileJournalEntry;
        $entry->caseFileId = $caseFileId;
        $entry->type = $type;
        $entry->occurredAt = $occurredAt;
        $entry->stateBefore = $stateBefore;
        $entry->stateAfter = $stateAfter;
        $entry->context = JsonObject::fromArray($context);
        $this->journal->insert($entry);
    }
}
