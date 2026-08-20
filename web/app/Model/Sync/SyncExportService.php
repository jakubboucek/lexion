<?php declare(strict_types=1);

namespace App\Model\Sync;

use App\Model\CaseFile\CaseFile;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileEventRepository;
use App\Model\CaseFile\CaseFileRelation;
use App\Model\CaseFile\CaseFileRelationRepository;
use App\Model\CaseFile\CaseFileRepository;
use Nette\Utils\Json;


/**
 * Writes the sending side of a sync: a JSONL file per SyncFormat.
 *
 * A case file is exported as ONE record carrying its events and relations
 * inside it. That is what lets both ends work in constant memory: the reader
 * has everything it needs to decide about one case the moment it has read a
 * single line, and can drop it before reading the next.
 *
 * The raw JSON payloads travel as strings, not as nested objects: the columns
 * are verbatim snapshots of what the source said (see CLAUDE.md), so they are
 * copied byte for byte instead of being decoded and re-encoded on the way.
 *
 * Relations are nested under their source case, which is complete for
 * everything the projection produces - it only ever writes rows whose src is
 * the projected case. A hand-made relation pointing out of a case we do not
 * hold would not be exported; there is no way to create one today.
 *
 * The export is split into parts because the receiving side takes the file
 * through an HTTP upload, and hosting upload limits are far below the size of
 * the whole records. Each part is a complete, self-contained file (its own
 * meta and codelist records) and the merge is order-independent, so parts may
 * be applied in any order, repeatedly, or not at all.
 */
final readonly class SyncExportService
{
    /** Case files per query round - keeps the working set small while streaming. */
    private const int ChunkSize = 200;

    public function __construct(
        private CaseFileRepository $caseFiles,
        private CaseFileEventRepository $events,
        private CaseFileRelationRepository $relations,
        private SyncCodelistService $codelists,
    ) {
    }


    /**
     * Splits the records into parts of at most $partSize case files and
     * returns their id ranges. Ranges rather than offsets: a part stays the
     * same slice even if rows are added while the operator downloads the
     * previous one.
     *
     * @return list<array{part: int, fromId: int, toId: int, count: int}>
     */
    public function parts(int $partSize): array
    {
        $ids = $this->caseFiles->allIds();
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
        int $part,
        int $parts,
        int $fromId,
        int $toId,
        string $origin,
        \DateTimeImmutable $generatedAt,
    ): void
    {
        $ids = array_values(array_filter(
            $this->caseFiles->allIds(),
            static fn(int $id): bool => $id >= $fromId && $id <= $toId,
        ));

        $write(self::line([
            'type' => RecordType::Meta->value,
            'format' => SyncFormat::Format,
            'version' => SyncFormat::Version,
            'generatedAt' => $generatedAt->format(\DATE_ATOM),
            'origin' => $origin,
            'part' => $part,
            'parts' => $parts,
            'caseFiles' => count($ids),
        ]));

        foreach ($this->codelists->export() as $codelist => $rows) {
            $write(self::line([
                'type' => RecordType::Codelist->value,
                'codelist' => $codelist,
                'rows' => $rows,
            ]));
        }

        foreach (array_chunk($ids, self::ChunkSize) as $chunk) {
            $cases = $this->caseFiles->findByIds($chunk);
            $events = $this->events->findByCaseFiles($chunk);
            $relations = $this->relations->findBySrcCaseFiles(array_values($cases));

            foreach ($chunk as $id) {
                $case = $cases[$id] ?? null;
                if ($case === null) {
                    continue; // deleted between the id scan and this round
                }
                $write(self::line($this->caseRecord(
                    $case,
                    $events[$id] ?? [],
                    $relations[$case->key()] ?? [],
                )));
            }
        }
    }


    /**
     * @param list<CaseFileEvent> $events
     * @param list<CaseFileRelation> $relations
     * @return array<string, mixed>
     */
    private function caseRecord(CaseFile $case, array $events, array $relations): array
    {
        return [
            'type' => RecordType::CaseFile->value,
            'case' => [
                'court' => $case->courtKod,
                'registry' => $case->registryNorm,
                'senate' => $case->senate,
                'number' => $case->bcNumber,
                'year' => $case->year,
                'infosoudJson' => $case->infosoudJson,
                'infosoudAt' => self::stamp($case->infosoudAt),
                'isirJson' => $case->isirJson,
                'isirAt' => self::stamp($case->isirAt),
                'createdAt' => self::stamp($case->createdAt),
            ],
            'events' => array_map(self::eventRecord(...), $events),
            'relations' => array_map(self::relationRecord(...), $relations),
        ];
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
            'cancelled' => $event->cancelled,
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
