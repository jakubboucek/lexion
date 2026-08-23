<?php declare(strict_types=1);

namespace App\Model\Sync;

use App\Model\CaseFile\CaseFile;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileEventRepository;
use App\Model\CaseFile\CaseFileRelation;
use App\Model\CaseFile\CaseFileRelationRepository;
use App\Model\CaseFile\CaseFileRepository;
use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\RelationTypeRepository;
use App\Model\Log\LogRunJsonlFile;
use Nette\Database\Explorer;


/**
 * Merges one `case_file` record - the case, its timeline events and its
 * relations - into the records. See SyncImportService for the properties the
 * whole merge holds to; what is specific here:
 *
 * SOURCES ARE WEIGHED SEPARATELY. A case whose infosoud data is newer here
 * and whose ISIR data is newer there ends up with the newest of both.
 *
 * EVENTS ARE WEIGHED SEPARATELY FROM THE CASE. Which events exist and their
 * dates belong to the case snapshot; a fetched event detail is its own
 * acquisition, so one environment can hold a newer case and an older detail
 * at the same time and both halves land where they belong.
 *
 * PAIRING IS THE FRAGILE PART. Events pair on CaseFileEvent::pairingKey(),
 * which is built on the upstream `poradi` - a number that is not stable over
 * time. When the two sides disagree about which events exist, or pair two
 * events carrying different dates, that is the signature of upstream
 * renumbering: the case file is left untouched and the problem is logged.
 * Guessing would silently attach one event's detail to another.
 *
 * CODELIST KEYS ARE CHECKED WHERE THEY CAN BREAK THE INSERT. The synced
 * tables have exactly two hard foreign keys into codelists - the case's court
 * and a relation's type - and either would make the insert fail outright, so
 * an unknown one skips this case file and leaves it for a run after the
 * codelist migration. Everything else (the case's registry, the courts and
 * registries of referenced cases) has no foreign key on purpose, because the
 * reference may point outside our codelists entirely: relations to prosecutor
 * files carry registry norms the codelist does not know.
 */
final readonly class CaseFileMergeService
{
    public function __construct(
        private Explorer $db,
        private CaseFileRepository $caseFiles,
        private CaseFileEventRepository $events,
        private CaseFileRelationRepository $relations,
        private CourtRepository $courts,
        private RelationTypeRepository $relationTypes,
    ) {
    }


    public function merge(SyncRecord $record, SyncImportReport $report, LogRunJsonlFile $problems): void
    {
        $label = self::label($record);
        try {
            $incoming = self::readCaseFile($record->child('case'));
            $incomingEvents = self::readEvents($record->children('events'));
            $incomingRelations = self::readRelations($record->children('relations'));
        } catch (SyncException $e) {
            $this->skip($report, $problems, new SyncProblem($label, SyncProblemReason::InvalidRecord, $e->getMessage()));
            return;
        }

        $unknown = $this->unknownCodelistKey($incoming, $incomingRelations);
        if ($unknown !== null) {
            $this->skip($report, $problems, new SyncProblem($label, SyncProblemReason::UnknownCodelistKey, $unknown));
            return;
        }

        $local = $this->caseFiles->getByIdentity(
            $incoming->courtKod,
            $incoming->registryNorm,
            $incoming->senate,
            $incoming->bcNumber,
            $incoming->year,
        );
        if ($local === null) {
            $this->create($incoming, $incomingEvents, $incomingRelations, $report);
            return;
        }

        $this->mergeExisting($local, $incoming, $incomingEvents, $incomingRelations, $label, $report, $problems);
    }


    /**
     * @param list<CaseFileEvent> $events
     * @param list<CaseFileRelation> $relations
     */
    private function create(
        CaseFile $incoming,
        array $events,
        array $relations,
        SyncImportReport $report,
    ): void
    {
        $this->db->getConnection()->transaction(function () use ($incoming, $events, $relations, $report): void {
            $stored = $this->caseFiles->insert($incoming);
            foreach ($events as $event) {
                $event->caseFileId = $stored->id;
                $this->events->insert($event);
                $report->eventsCreated++;
            }
            foreach ($relations as $relation) {
                self::pointRelationAt($relation, $stored);
                $this->relations->insert($relation);
                $report->relationsCreated++;
            }
        });
        $report->caseFilesCreated++;
    }


    /**
     * @param list<CaseFileEvent> $incomingEvents
     * @param list<CaseFileRelation> $incomingRelations
     */
    private function mergeExisting(
        CaseFile $local,
        CaseFile $incoming,
        array $incomingEvents,
        array $incomingRelations,
        string $label,
        SyncImportReport $report,
        LogRunJsonlFile $problems,
    ): void
    {
        $incomingIsNewer = Freshness::isNewer($incoming->infosoudAt, $local->infosoudAt);
        $localIsNewer = Freshness::isNewer($local->infosoudAt, $incoming->infosoudAt);

        $localByKey = self::keyEvents($this->events->findByCaseFile($local->id));
        $incomingByKey = self::keyEvents($incomingEvents);

        // Additive merge: the newer snapshot must know everything the older
        // one does. When it does not, the two timelines cannot be paired.
        $missing = match (true) {
            $incomingIsNewer => array_diff_key($localByKey, $incomingByKey),
            $localIsNewer => array_diff_key($incomingByKey, $localByKey),
            default => array_diff_key($localByKey, $incomingByKey) + array_diff_key($incomingByKey, $localByKey),
        };
        if ($missing !== []) {
            $this->skip($report, $problems, new SyncProblem(
                $label,
                SyncProblemReason::EventMissingInNewerSnapshot,
                implode(', ', array_slice(array_keys($missing), 0, 5)),
            ));
            return;
        }

        foreach ($incomingByKey as $key => $event) {
            $paired = $localByKey[$key] ?? null;
            if ($paired !== null && self::dayKey($event->eventDate) !== self::dayKey($paired->eventDate)) {
                $this->skip($report, $problems, new SyncProblem($label, SyncProblemReason::EventDateMismatch, $key));
                return;
            }
        }

        $changed = $this->db->getConnection()->transaction(
            fn(): bool => $this->apply($local, $incoming, $incomingByKey, $localByKey, $incomingRelations, $incomingIsNewer, $report),
        );
        $changed ? $report->caseFilesUpdated++ : $report->caseFilesUnchanged++;
    }


    /**
     * The write half of the merge, already validated. Returns whether
     * anything actually changed - an unchanged case is the normal outcome of
     * re-applying a file, and the operator wants to see the difference.
     *
     * @param array<string, CaseFileEvent> $incomingByKey
     * @param array<string, CaseFileEvent> $localByKey
     * @param list<CaseFileRelation> $incomingRelations
     */
    private function apply(
        CaseFile $local,
        CaseFile $incoming,
        array $incomingByKey,
        array $localByKey,
        array $incomingRelations,
        bool $incomingIsNewer,
        SyncImportReport $report,
    ): bool
    {
        $changed = false;

        $patch = new CaseFile;
        if ($incomingIsNewer) {
            $patch->infosoudJson = $incoming->infosoudJson;
            $patch->infosoudAt = $incoming->infosoudAt;
            $changed = true;
        }
        if (Freshness::isNewer($incoming->isirAt, $local->isirAt)) {
            $patch->isirJson = $incoming->isirJson;
            $patch->isirAt = $incoming->isirAt;
            $changed = true;
        }
        // First-seen time is data too: keep the earlier of the two.
        if ($incoming->createdAt < $local->createdAt) {
            $patch->createdAt = $incoming->createdAt;
            $changed = true;
        }
        if ($changed) {
            $this->caseFiles->update($local->id, $patch);
        }

        foreach ($incomingByKey as $key => $event) {
            $paired = $localByKey[$key] ?? null;
            if ($paired === null) {
                $event->caseFileId = $local->id;
                $this->events->insert($event);
                $report->eventsCreated++;
                $changed = true;
                continue;
            }
            if ($this->mergeEvent($paired, $event, $incomingIsNewer)) {
                $report->eventsUpdated++;
                $changed = true;
            }
        }

        $known = [];
        foreach ($this->relations->findBySrc(
            $local->courtKod,
            $local->registryNorm,
            $local->senate,
            $local->bcNumber,
            $local->year,
        ) as $relation) {
            $known[self::relationKey($relation)] = true;
        }
        foreach ($incomingRelations as $relation) {
            $key = self::relationKey($relation);
            if (isset($known[$key])) {
                continue;
            }
            self::pointRelationAt($relation, $local);
            $this->relations->insert($relation);
            $known[$key] = true;
            $report->relationsCreated++;
            $changed = true;
        }

        return $changed;
    }


    /**
     * Merges one paired event. The thin fields follow the case snapshot (they
     * are read off it), the fetched detail is weighed on its own stamp.
     */
    private function mergeEvent(CaseFileEvent $local, CaseFileEvent $incoming, bool $incomingIsNewer): bool
    {
        $patch = new CaseFileEvent;
        $changed = false;

        if ($incomingIsNewer) {
            if ($incoming->cancelled !== $local->cancelled) {
                $patch->cancelled = $incoming->cancelled;
                $changed = true;
            }
            if ($incoming->upstreamId !== $local->upstreamId) {
                $patch->upstreamId = $incoming->upstreamId;
                $changed = true;
            }
            if ($incoming->parentEventOrder !== $local->parentEventOrder) {
                $patch->parentEventOrder = $incoming->parentEventOrder;
                $changed = true;
            }
        }
        if (Freshness::isNewer($incoming->detailFetchedAt, $local->detailFetchedAt)) {
            $patch->detailJson = $incoming->detailJson;
            $patch->detailFetchedAt = $incoming->detailFetchedAt;
            $changed = true;
        }
        if ($incoming->createdAt < $local->createdAt) {
            $patch->createdAt = $incoming->createdAt;
            $changed = true;
        }

        if ($changed) {
            $this->events->update($local->id, $patch);
        }
        return $changed;
    }


    /**
     * The first codelist key of the record this side does not have, or null
     * when all of them are known. Both lookups read the cached codelist
     * snapshot, so this costs no queries.
     *
     * @param list<CaseFileRelation> $relations
     */
    private function unknownCodelistKey(CaseFile $caseFile, array $relations): ?string
    {
        if ($this->courts->getByKod($caseFile->courtKod) === null) {
            return 'court ' . $caseFile->courtKod;
        }
        $known = $this->relationTypes->findAll();
        foreach ($relations as $relation) {
            if (!isset($known[$relation->relationType])) {
                return 'relation_type ' . $relation->relationType;
            }
        }
        return null;
    }


    private function skip(SyncImportReport $report, LogRunJsonlFile $problems, SyncProblem $problem): void
    {
        $report->addProblem($problem);
        $report->caseFilesSkipped++;
        $problems->write($problem->toLogData());
    }


    private static function readCaseFile(SyncRecord $case): CaseFile
    {
        $entity = new CaseFile;
        $entity->courtKod = $case->text('court');
        $entity->registryNorm = $case->text('registry');
        $entity->senate = $case->number('senate');
        $entity->bcNumber = $case->number('number');
        $entity->year = $case->number('year');
        $entity->infosoudJson = $case->optionalText('infosoudJson');
        $entity->infosoudAt = $case->optionalMoment('infosoudAt');
        $entity->isirJson = $case->optionalText('isirJson');
        $entity->isirAt = $case->optionalMoment('isirAt');
        $entity->createdAt = $case->moment('createdAt');
        return $entity;
    }


    /**
     * @param list<SyncRecord> $records
     * @return list<CaseFileEvent>
     */
    private static function readEvents(array $records): array
    {
        $events = [];
        foreach ($records as $item) {
            $event = new CaseFileEvent;
            $event->source = $item->text('source');
            $event->eventCode = $item->text('eventCode');
            $event->eventOrder = $item->optionalNumber('eventOrder');
            $event->upstreamId = $item->optionalText('upstreamId');
            $event->eventDate = $item->optionalMoment('eventDate');
            $event->cancelled = $item->flag('cancelled');
            $event->parentEventOrder = $item->optionalNumber('parentOrder');
            $event->refCourtKod = $item->optionalText('refCourt');
            $event->refRegistryNorm = $item->optionalText('refRegistry');
            $event->refSenate = $item->optionalNumber('refSenate');
            $event->refBcNumber = $item->optionalNumber('refNumber');
            $event->refYear = $item->optionalNumber('refYear');
            $event->detailJson = $item->optionalText('detailJson');
            $event->detailFetchedAt = $item->optionalMoment('detailFetchedAt');
            $event->createdAt = $item->moment('createdAt');
            $events[] = $event;
        }
        return $events;
    }


    /**
     * Relations of one case, deduplicated by their natural key - the same
     * pair may not be inserted twice, and a file is not trusted to be clean.
     *
     * @param list<SyncRecord> $records
     * @return list<CaseFileRelation>
     */
    private static function readRelations(array $records): array
    {
        $relations = [];
        foreach ($records as $item) {
            $relation = new CaseFileRelation;
            $relation->dstCourtKod = $item->optionalText('dstCourt');
            $relation->dstRegistryNorm = $item->text('dstRegistry');
            $relation->dstSenate = $item->number('dstSenate');
            $relation->dstBcNumber = $item->number('dstNumber');
            $relation->dstYear = $item->number('dstYear');
            $relation->relationType = $item->text('relationType');
            $relation->source = $item->text('source');
            $relation->note = $item->optionalText('note');
            $relation->createdAt = $item->moment('createdAt');
            $relations[self::relationKey($relation)] = $relation;
        }
        return array_values($relations);
    }


    /**
     * Events keyed for pairing. Both ends derive the key from the same entity
     * method, so they cannot drift apart.
     *
     * @param list<CaseFileEvent> $events
     * @return array<string, CaseFileEvent>
     */
    private static function keyEvents(array $events): array
    {
        $keyed = [];
        foreach ($events as $event) {
            $keyed[$event->source . '|' . $event->pairingKey()] = $event;
        }
        return $keyed;
    }


    /** Natural key of a relation within its source case. */
    private static function relationKey(CaseFileRelation $relation): string
    {
        return implode('|', [
            $relation->dstCourtKod ?? '',
            $relation->dstRegistryNorm,
            $relation->dstSenate,
            $relation->dstBcNumber,
            $relation->dstYear,
            $relation->relationType,
            $relation->source,
        ]);
    }


    /** The source side of a relation is the case file it was exported under. */
    private static function pointRelationAt(CaseFileRelation $relation, CaseFile $caseFile): void
    {
        $relation->srcCourtKod = $caseFile->courtKod;
        $relation->srcRegistryNorm = $caseFile->registryNorm;
        $relation->srcSenate = $caseFile->senate;
        $relation->srcBcNumber = $caseFile->bcNumber;
        $relation->srcYear = $caseFile->year;
    }


    private static function dayKey(?\DateTimeInterface $date): string
    {
        return $date?->format('Y-m-d') ?? '';
    }


    /** Case identity for the problem list, built defensively - the record may be junk. */
    private static function label(SyncRecord $record): string
    {
        $case = $record->raw('case');
        if (!is_array($case)) {
            return '?';
        }
        $part = static fn(string $key): string
            => is_scalar($case[$key] ?? null) ? (string) $case[$key] : '?';
        return sprintf('%s %s %s %s/%s', $part('court'), $part('senate'), $part('registry'), $part('number'), $part('year'));
    }
}
