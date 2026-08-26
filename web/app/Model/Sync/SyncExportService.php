<?php declare(strict_types=1);

namespace App\Model\Sync;

use App\Model\CaseFile\CaseFile;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileEventRepository;
use App\Model\CaseFile\CaseFileRelation;
use App\Model\CaseFile\CaseFileRelationRepository;
use App\Model\CaseFile\CaseFileRepository;
use App\Model\Hearing\HearingObservation;
use App\Model\Hearing\HearingRepository;
use App\Model\Hearing\HearingRoom;
use App\Model\Hearing\HearingRoomRepository;
use Nette\Utils\Json;


/**
 * Writes the sending side of a sync: a JSONL file per SyncFormat.
 *
 * Records nest what belongs to them - a case file carries its events and
 * relations, a hearing carries its observations. That is what lets both ends
 * work in constant memory: the reader has everything it needs to decide about
 * one thing the moment it has read a single line, and can drop it before
 * reading the next.
 *
 * Raw payloads travel as strings, not as nested objects: the columns are
 * verbatim snapshots of what the source said (see CLAUDE.md), so they are
 * copied byte for byte instead of being decoded and re-encoded on the way.
 *
 * Surrogate ids never travel. A case file is addressed by its five-tuple, a
 * hearing by venue court + case + minute, a room by (court, label) - and a
 * hearing's link to its case travels as that case's identity, so the
 * receiving side can mint its own ids and still land the link.
 *
 * The export is split into parts because the receiving side takes the file
 * through an HTTP upload, and hosting upload limits are far below the size of
 * the whole records. Each part is a complete, self-contained file (its own
 * meta and codelist records) and the merge is order-independent, so parts may
 * be applied in any order, repeatedly, or not at all.
 */
final readonly class SyncExportService
{
    /** Records per query round - keeps the working set small while streaming. */
    private const int ChunkSize = 200;

    public function __construct(
        private CaseFileRepository $caseFiles,
        private CaseFileEventRepository $events,
        private CaseFileRelationRepository $relations,
        private HearingRepository $hearings,
        private HearingRoomRepository $rooms,
        private SyncCodelistService $codelists,
    ) {
    }


    /**
     * Splits a dataset into parts of at most $partSize records and returns
     * their id ranges. Ranges rather than offsets: a part stays the same slice
     * even if rows are added while the operator downloads the previous one.
     *
     * @return list<array{part: int, fromId: int, toId: int, count: int}>
     */
    public function parts(SyncDataset $dataset, int $partSize): array
    {
        $ids = $this->idsOf($dataset);
        if ($ids === []) {
            return [];
        }

        $parts = [];
        foreach (array_chunk($ids, max(1, $partSize)) as $index => $chunk) {
            $parts[] = [
                'part' => $index + 1,
                'fromId' => $chunk[0],
                'toId' => $chunk[count($chunk) - 1],
                'count' => count($chunk),
            ];
        }
        return $parts;
    }


    /**
     * Writes one part through the given sink, line by line. The sink is fed
     * whole lines (including the newline), so the caller decides whether they
     * go to the HTTP output, a file, or a test buffer.
     *
     * @param callable(string): void $write
     */
    public function writePart(
        callable $write,
        SyncDataset $dataset,
        int $part,
        int $parts,
        int $fromId,
        int $toId,
        string $origin,
        \DateTimeImmutable $generatedAt,
    ): void
    {
        $ids = array_values(array_filter(
            $this->idsOf($dataset),
            static fn(int $id): bool => $id >= $fromId && $id <= $toId,
        ));

        $write(self::line([
            'type' => RecordType::Meta->value,
            'format' => SyncFormat::Format,
            'version' => SyncFormat::Version,
            'dataset' => $dataset->value,
            'generatedAt' => $generatedAt->format(\DATE_ATOM),
            'origin' => $origin,
            'part' => $part,
            'parts' => $parts,
            'records' => count($ids),
        ]));

        foreach ($this->codelists->export() as $codelist => $rows) {
            $write(self::line([
                'type' => RecordType::Codelist->value,
                'codelist' => $codelist,
                'rows' => $rows,
            ]));
        }

        foreach (array_chunk($ids, self::ChunkSize) as $chunk) {
            match ($dataset) {
                SyncDataset::CaseFiles => $this->writeCaseFiles($write, $chunk),
                SyncDataset::HearingRooms => $this->writeHearingRooms($write, $chunk),
                SyncDataset::Hearings => $this->writeHearings($write, $chunk),
            };
        }
    }


    /** @return list<int> */
    private function idsOf(SyncDataset $dataset): array
    {
        return match ($dataset) {
            SyncDataset::CaseFiles => $this->caseFiles->allIds(),
            SyncDataset::HearingRooms => array_map(static fn(HearingRoom $room): int => $room->id, $this->rooms->findAll()),
            SyncDataset::Hearings => $this->hearings->allIds(),
        };
    }


    /**
     * @param callable(string): void $write
     * @param list<int> $ids
     */
    private function writeCaseFiles(callable $write, array $ids): void
    {
        $cases = $this->caseFiles->findByIds($ids);
        $events = $this->events->findByCaseFiles($ids);
        $relations = $this->relations->findBySrcCaseFiles(array_values($cases));

        foreach ($ids as $id) {
            $case = $cases[$id] ?? null;
            if ($case === null) {
                continue; // deleted between the id scan and this round
            }
            $write(self::line([
                'type' => RecordType::CaseFile->value,
                'case' => [
                    'court' => $case->courtKod,
                    'registry' => $case->registryNorm,
                    'senate' => $case->senate,
                    'number' => $case->bcNumber,
                    'year' => $case->year,
                    // Derived from infosoudJson, so they travel with it -
                    // the importing side merges rows, it does not reproject.
                    'subject' => $case->subject,
                    'status' => $case->status,
                    'statusDate' => $case->statusDate?->format('Y-m-d'),
                    'intakeKind' => $case->intakeKind,
                    'infosoudJson' => $case->infosoudJson,
                    'infosoudAt' => self::stamp($case->infosoudAt),
                    'isirJson' => $case->isirJson,
                    'isirAt' => self::stamp($case->isirAt),
                    'createdAt' => self::stamp($case->createdAt),
                ],
                'events' => array_map(self::eventRecord(...), $events[$id] ?? []),
                'relations' => array_map(self::relationRecord(...), $relations[$case->key()] ?? []),
            ]));
        }
    }


    /**
     * Rooms are exported as data, not as a codelist: they are harvested by the
     * scan and curated by hand, so the two sides legitimately differ and have
     * to merge rather than match.
     *
     * @param callable(string): void $write
     * @param list<int> $ids
     */
    private function writeHearingRooms(callable $write, array $ids): void
    {
        $wanted = array_flip($ids);
        foreach ($this->rooms->findAll() as $room) {
            if (!isset($wanted[$room->id])) {
                continue;
            }
            $write(self::line([
                'type' => RecordType::HearingRoom->value,
                'court' => $room->courtKod,
                'label' => $room->label,
                'kind' => $room->kind->value,
                'offSite' => $room->offSite,
                'note' => $room->note,
                'firstSeen' => self::stamp($room->firstSeen),
                'lastSeen' => self::stamp($room->lastSeen),
                'retiredAt' => self::stamp($room->retiredAt),
                'createdAt' => self::stamp($room->createdAt),
            ]));
        }
    }


    /**
     * @param callable(string): void $write
     * @param list<int> $ids
     */
    private function writeHearings(callable $write, array $ids): void
    {
        $hearings = $this->hearings->findByIds($ids);
        $observations = $this->hearings->findObservationsByHearings($ids);
        // The link to a case travels as the case's identity; only a handful of
        // hearings are bound at all, so the cases are fetched in one query.
        $boundIds = [];
        foreach ($hearings as $hearing) {
            if ($hearing->caseFileId !== null) {
                $boundIds[] = $hearing->caseFileId;
            }
        }
        $boundCases = $this->caseFiles->findByIds(array_values(array_unique($boundIds)));

        foreach ($ids as $id) {
            $hearing = $hearings[$id] ?? null;
            if ($hearing === null) {
                continue;
            }
            $bound = $hearing->caseFileId !== null ? $boundCases[$hearing->caseFileId] ?? null : null;
            $write(self::line([
                'type' => RecordType::Hearing->value,
                'hearing' => [
                    'venueCourt' => $hearing->venueCourtKod,
                    'registry' => $hearing->registryNorm,
                    'senate' => $hearing->senate,
                    'number' => $hearing->bcNumber,
                    'year' => $hearing->year,
                    'date' => $hearing->hearingDate->format('Y-m-d'),
                    'time' => $hearing->hearingTime->format('H:i:s'),
                    'room' => $hearing->room,
                    'hearingType' => $hearing->hearingType,
                    'judge' => $hearing->judge,
                    'cancelled' => $hearing->cancelled,
                    'nonPublic' => $hearing->nonPublic,
                    'result' => $hearing->result,
                    'courtBinding' => $hearing->courtBinding->value,
                    'lastSeenAt' => self::stamp($hearing->lastSeenAt),
                    'createdAt' => self::stamp($hearing->createdAt),
                ],
                'boundCase' => $bound !== null ? self::caseIdentity($bound) : null,
                'observations' => array_map(self::observationRecord(...), $observations[$id] ?? []),
            ]));
        }
    }


    /** @return array<string, mixed> */
    private static function eventRecord(CaseFileEvent $event): array
    {
        return [
            'source' => $event->source,
            'eventCode' => $event->eventCode,
            'eventOrder' => $event->eventOrder,
            'upstreamId' => $event->upstreamId,
            'eventDate' => $event->eventDate?->format('Y-m-d'),
            // Derived from detailJson - travel with it (see the case record).
            'hearingAt' => self::stamp($event->hearingAt),
            'hearingRoom' => $event->hearingRoom,
            'hearingType' => $event->hearingType,
            'cancelled' => $event->cancelled,
            'parentOrder' => $event->parentEventOrder,
            'refCourt' => $event->refCourtKod,
            'refRegistry' => $event->refRegistryNorm,
            'refSenate' => $event->refSenate,
            'refNumber' => $event->refBcNumber,
            'refYear' => $event->refYear,
            'detailJson' => $event->detailJson,
            'detailFetchedAt' => self::stamp($event->detailFetchedAt),
            'createdAt' => self::stamp($event->createdAt),
        ];
    }


    /**
     * The source side of a relation is the case file the record hangs under,
     * so only the target travels.
     *
     * @return array<string, mixed>
     */
    private static function relationRecord(CaseFileRelation $relation): array
    {
        return [
            'dstCourt' => $relation->dstCourtKod,
            'dstRegistry' => $relation->dstRegistryNorm,
            'dstSenate' => $relation->dstSenate,
            'dstNumber' => $relation->dstBcNumber,
            'dstYear' => $relation->dstYear,
            'relationType' => $relation->relationType,
            'source' => $relation->source,
            'note' => $relation->note,
            'createdAt' => self::stamp($relation->createdAt),
        ];
    }


    /** @return array<string, mixed> */
    private static function observationRecord(HearingObservation $observation): array
    {
        return [
            'source' => $observation->source->value,
            'observedAt' => self::stamp($observation->observedAt),
            'room' => $observation->room,
            'rawJson' => $observation->rawJson,
            'createdAt' => self::stamp($observation->createdAt),
        ];
    }


    /** @return array<string, mixed> */
    private static function caseIdentity(CaseFile $caseFile): array
    {
        return [
            'court' => $caseFile->courtKod,
            'registry' => $caseFile->registryNorm,
            'senate' => $caseFile->senate,
            'number' => $caseFile->bcNumber,
            'year' => $caseFile->year,
        ];
    }


    private static function stamp(?\DateTimeInterface $time): ?string
    {
        return $time?->format(\DATE_ATOM);
    }


    /** @param array<string, mixed> $record */
    private static function line(array $record): string
    {
        // Json::encode escapes newlines, so a record can never break the
        // one-record-per-line contract.
        return Json::encode($record) . "\n";
    }
}
