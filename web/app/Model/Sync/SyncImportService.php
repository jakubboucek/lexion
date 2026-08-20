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
use Nette\Database\Explorer;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Tracy\ILogger;


/**
 * Reads the receiving side of a sync: merges a JSONL file (see SyncFormat)
 * into the records.
 *
 * THE MERGE IS ADDITIVE. Nothing is ever deleted and no value is ever
 * replaced by an older one, which buys three properties the whole design
 * leans on: applying the same file twice changes nothing, applying files in
 * any order gives the same result, and a stale file can do no harm. That is
 * why parts may be uploaded in any order and a half-finished import can
 * simply be repeated.
 *
 * FRESHNESS IS DOMAIN FRESHNESS. Which side wins is decided by the stamps
 * that say when the data was fetched from the source (`infosoud_at`,
 * `isir_at`, `detail_fetched_at`), never by `updated_at` - on the receiving
 * side that column means "when I imported it", so using it would let a fresh
 * import lose to its own bookkeeping. Sources are weighed separately, so a
 * case whose infosoud data is newer here and whose ISIR data is newer there
 * ends up with the newest of both.
 *
 * EVENTS ARE WEIGHED SEPARATELY FROM THE CASE. The timeline (which events
 * exist, their dates) belongs to the case snapshot, but a fetched event
 * detail is its own acquisition - one environment can hold a newer case and
 * an older detail at the same time, and both halves land where they belong.
 *
 * CODELISTS ARE CHECKED WHERE THEY CAN ACTUALLY BREAK SOMETHING. A row that
 * differs in content cannot corrupt anything - it only drives URLs and
 * display - so the header comparison is a warning in the report, never a
 * veto. What does break an import is a key the data points at and this side
 * does not have, and the synced tables have exactly two such hard foreign
 * keys: a case file's court and a relation's type. They are checked per case
 * file, so one unknown court costs a handful of case files instead of the
 * whole run; the skipped ones land on the next run after the codelist
 * migration. Everything else - a case's registry, the courts and registries
 * of referenced cases - has no foreign key on purpose, because the reference
 * may point outside our codelists entirely (a prosecutor file is a real,
 * existing example).
 *
 * PAIRING IS THE FRAGILE PART. Events pair on
 * CaseFileEvent::pairingKey(), which is built on the upstream `poradi` - a
 * number that is not stable over time. When the two sides disagree about
 * which events exist, or pair two events that carry different dates, that is
 * the signature of upstream renumbering: the case file is left untouched, the
 * problem is logged, and the import moves on. Guessing would silently attach
 * one event's detail to another.
 */
final readonly class SyncImportService
{
    public function __construct(
        private Explorer $db,
        private CaseFileRepository $caseFiles,
        private CaseFileEventRepository $events,
        private CaseFileRelationRepository $relations,
        private SyncCodelistService $codelists,
        private CourtRepository $courts,
        private RelationTypeRepository $relationTypes,
        private ILogger $logger,
    ) {
    }


    /** @throws SyncException the file is unreadable, incompatible or malformed */
    public function import(string $path): SyncImportReport
    {
        // zlib reads a plain file as-is, so one call handles both the gzipped
        // export and a file somebody unpacked on the way.
        $handle = @gzopen($path, 'r');
        if ($handle === false) {
            throw new SyncException('Soubor se nepodařilo otevřít.');
        }

        try {
            $report = new SyncImportReport;
            // The header ends at the first data record, which is already read
            // by the time the codelists have been checked - so it is handed
            // over rather than re-read.
            $record = $this->readHeader($handle, $report);

            while ($record !== null) {
                if (self::typeOf($record) !== RecordType::CaseFile) {
                    throw new SyncException('Soubor obsahuje neznámý nebo neočekávaný typ záznamu.');
                }
                $this->importCaseFile($record, $report);
                $record = self::nextRecord($handle);
            }
            return $report;
        } finally {
            gzclose($handle);
        }
    }


    /**
     * The mandatory first line: says whose file this is and which format
     * version wrote it. Anything else means we are not reading a sync file at
     * all, so nothing beyond it is even attempted.
     *
     * @param array<mixed>|null $record
     */
    private function readMeta(?array $record, SyncImportReport $report): void
    {
        if ($record === null
            || self::typeOf($record) !== RecordType::Meta
            || ($record['format'] ?? null) !== SyncFormat::Format
        ) {
            throw new SyncException('Tohle není synchronizační soubor Lexionu (chybí úvodní hlavička).');
        }

        $version = $record['version'] ?? null;
        if ($version !== SyncFormat::Version) {
            throw new SyncException(sprintf(
                'Soubor je ve formátu verze %s, tahle instalace čte verzi %d. Sjednoťte verzi kódu na obou stranách.',
                is_scalar($version) ? (string) $version : '?',
                SyncFormat::Version,
            ));
        }

        $report->origin = is_string($record['origin'] ?? null) ? $record['origin'] : null;
        $report->generatedAt = self::optionalMoment($record, 'generatedAt');
        $report->part = self::optionalNumber($record, 'part') ?? 1;
        $report->parts = self::optionalNumber($record, 'parts') ?? 1;
    }


    /**
     * Reads the whole header - the meta line and the codelist records that
     * follow it - and returns the first data record, or null for a file that
     * carries none. Codelist differences are recorded on the report and
     * logged; they never stop the import (see the class docblock).
     *
     * @param resource $handle
     * @return array<mixed>|null
     */
    private function readHeader($handle, SyncImportReport $report): ?array
    {
        $this->readMeta(self::nextRecord($handle), $report);

        $codelists = [];
        $first = null;
        while (($record = self::nextRecord($handle)) !== null) {
            if (self::typeOf($record) !== RecordType::Codelist) {
                $first = $record;
                break;
            }
            $name = $record['codelist'] ?? null;
            $rows = $record['rows'] ?? null;
            if (!is_string($name) || !is_array($rows)) {
                throw new SyncException('Záznam s číselníkem je poškozený.');
            }
            $codelists[$name] = $rows;
        }

        if ($codelists === []) {
            throw new SyncException('V souboru chybí záznamy s číselníky.');
        }
        $report->codelistDifferences = $this->codelists->compare($codelists);
        $this->logCodelistDifferences($report);

        return $first;
    }


    /** @param array<mixed> $record */
    private function importCaseFile(array $record, SyncImportReport $report): void
    {
        $label = self::caseLabel($record['case'] ?? null);
        try {
            $data = $record['case'] ?? null;
            if (!is_array($data)) {
                throw new SyncException('Záznam neobsahuje spis.');
            }
            $incoming = self::readCaseFile($data);
            $incomingEvents = self::readEvents($record['events'] ?? []);
            $incomingRelations = self::readRelations($record['relations'] ?? []);
        } catch (SyncException $e) {
            $this->reportProblem($report, new SyncProblem($label, SyncProblemReason::InvalidRecord, $e->getMessage()));
            return;
        }

        $unknown = $this->unknownCodelistKey($incoming, $incomingRelations);
        if ($unknown !== null) {
            $this->reportProblem($report, new SyncProblem($label, SyncProblemReason::UnknownCodelistKey, $unknown));
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
            $this->createCaseFile($incoming, $incomingEvents, $incomingRelations, $report);
            return;
        }

        $this->mergeCaseFile($local, $incoming, $incomingEvents, $incomingRelations, $label, $report);
    }


    /**
     * @param list<CaseFileEvent> $events
     * @param list<CaseFileRelation> $relations
     */
    private function createCaseFile(
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
    private function mergeCaseFile(
        CaseFile $local,
        CaseFile $incoming,
        array $incomingEvents,
        array $incomingRelations,
        string $label,
        SyncImportReport $report,
    ): void
    {
        $incomingIsNewer = self::isNewer($incoming->infosoudAt, $local->infosoudAt);
        $localIsNewer = self::isNewer($local->infosoudAt, $incoming->infosoudAt);

        $localByKey = self::keyEvents($this->events->findByCaseFile($local->id));
        $incomingByKey = self::keyEvents($incomingEvents);

        // Additive merge: the newer snapshot must know everything the older
        // one does. When it does not, the two timelines cannot be paired -
        // see the class docblock.
        $missing = match (true) {
            $incomingIsNewer => array_diff_key($localByKey, $incomingByKey),
            $localIsNewer => array_diff_key($incomingByKey, $localByKey),
            default => array_diff_key($localByKey, $incomingByKey) + array_diff_key($incomingByKey, $localByKey),
        };
        if ($missing !== []) {
            $this->reportProblem($report, new SyncProblem(
                $label,
                SyncProblemReason::EventMissingInNewerSnapshot,
                implode(', ', array_slice(array_keys($missing), 0, 5)),
            ));
            return;
        }

        foreach ($incomingByKey as $key => $event) {
            $paired = $localByKey[$key] ?? null;
            if ($paired !== null && self::dayKey($event->eventDate) !== self::dayKey($paired->eventDate)) {
                $this->reportProblem($report, new SyncProblem($label, SyncProblemReason::EventDateMismatch, $key));
                return;
            }
        }

        $changed = $this->db->getConnection()->transaction(
            fn(): bool => $this->applyMerge(
                $local,
                $incoming,
                $incomingByKey,
                $localByKey,
                $incomingRelations,
                $incomingIsNewer,
                $report,
            ),
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
    private function applyMerge(
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
        if (self::isNewer($incoming->isirAt, $local->isirAt)) {
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
        }
        if (self::isNewer($incoming->detailFetchedAt, $local->detailFetchedAt)) {
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
     * when all of them are known. Only the two hard foreign keys are looked
     * at - see the class docblock; both lookups read the cached codelist
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


    /** One line per drifted codelist - a line per row would flood the log. */
    private function logCodelistDifferences(SyncImportReport $report): void
    {
        $perCodelist = [];
        foreach ($report->codelistDifferences as $difference) {
            $perCodelist[$difference->codelist] = ($perCodelist[$difference->codelist] ?? 0) + 1;
        }
        foreach ($perCodelist as $codelist => $count) {
            $this->logger->log("Sync import: codelist {$codelist} differs from the file in {$count} row(s)", 'sync');
        }
    }


    private function reportProblem(SyncImportReport $report, SyncProblem $problem): void
    {
        $report->addProblem($problem);
        $this->logger->log($problem->logLine(), 'sync');
    }


    /** @param array<mixed> $record */
    private static function typeOf(array $record): ?RecordType
    {
        return RecordType::tryFrom(is_string($record['type'] ?? null) ? $record['type'] : '');
    }


    /**
     * Next non-empty record of the file, or null at the end.
     *
     * @param resource $handle
     * @return array<mixed>|null
     */
    private static function nextRecord($handle): ?array
    {
        while (($line = gzgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $record = Json::decode($line, forceArrays: true);
            } catch (JsonException $e) {
                throw new SyncException('Soubor obsahuje řádek, který není platný JSON.', previous: $e);
            }
            if (!is_array($record)) {
                throw new SyncException('Soubor obsahuje řádek, který není záznamem.');
            }
            return $record;
        }
        return null;
    }


    /** @param array<mixed> $data */
    private static function readCaseFile(array $data): CaseFile
    {
        $case = new CaseFile;
        $case->courtKod = self::text($data, 'court');
        $case->registryNorm = self::text($data, 'registry');
        $case->senate = self::number($data, 'senate');
        $case->bcNumber = self::number($data, 'number');
        $case->year = self::number($data, 'year');
        $case->infosoudJson = self::optionalText($data, 'infosoudJson');
        $case->infosoudAt = self::optionalMoment($data, 'infosoudAt');
        $case->isirJson = self::optionalText($data, 'isirJson');
        $case->isirAt = self::optionalMoment($data, 'isirAt');
        $case->createdAt = self::moment($data, 'createdAt');
        return $case;
    }


    /** @return list<CaseFileEvent> */
    private static function readEvents(mixed $data): array
    {
        if (!is_array($data)) {
            throw new SyncException('Seznam událostí je poškozený.');
        }
        $events = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                throw new SyncException('Událost není záznamem.');
            }
            $event = new CaseFileEvent;
            $event->source = self::text($item, 'source');
            $event->eventCode = self::text($item, 'eventCode');
            $event->eventOrder = self::optionalNumber($item, 'eventOrder');
            $event->upstreamId = self::optionalText($item, 'upstreamId');
            $event->eventDate = self::optionalMoment($item, 'eventDate');
            $event->cancelled = self::flag($item, 'cancelled');
            $event->refCourtKod = self::optionalText($item, 'refCourt');
            $event->refRegistryNorm = self::optionalText($item, 'refRegistry');
            $event->refSenate = self::optionalNumber($item, 'refSenate');
            $event->refBcNumber = self::optionalNumber($item, 'refNumber');
            $event->refYear = self::optionalNumber($item, 'refYear');
            $event->detailJson = self::optionalText($item, 'detailJson');
            $event->detailFetchedAt = self::optionalMoment($item, 'detailFetchedAt');
            $event->createdAt = self::moment($item, 'createdAt');
            $events[] = $event;
        }
        return $events;
    }


    /**
     * Relations of one case, deduplicated by their natural key - the same
     * pair may not be inserted twice, and a file is not trusted to be clean.
     *
     * @return list<CaseFileRelation>
     */
    private static function readRelations(mixed $data): array
    {
        if (!is_array($data)) {
            throw new SyncException('Seznam vazeb je poškozený.');
        }
        $relations = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                throw new SyncException('Vazba není záznamem.');
            }
            $relation = new CaseFileRelation;
            $relation->dstCourtKod = self::optionalText($item, 'dstCourt');
            $relation->dstRegistryNorm = self::text($item, 'dstRegistry');
            $relation->dstSenate = self::number($item, 'dstSenate');
            $relation->dstBcNumber = self::number($item, 'dstNumber');
            $relation->dstYear = self::number($item, 'dstYear');
            $relation->relationType = self::text($item, 'relationType');
            $relation->source = self::text($item, 'source');
            $relation->note = self::optionalText($item, 'note');
            $relation->createdAt = self::moment($item, 'createdAt');
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


    /** Is $a a strictly fresher acquisition than $b? A missing stamp is the oldest. */
    private static function isNewer(?\DateTimeInterface $a, ?\DateTimeInterface $b): bool
    {
        return $a !== null && ($b === null || $a > $b);
    }


    private static function dayKey(?\DateTimeInterface $date): string
    {
        return $date?->format('Y-m-d') ?? '';
    }


    /** Case identity for the problem list, built defensively - the record may be junk. */
    private static function caseLabel(mixed $data): string
    {
        if (!is_array($data)) {
            return '?';
        }
        $part = static fn(string $key): string
            => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '?';
        return sprintf(
            '%s %s %s %s/%s',
            $part('court'),
            $part('senate'),
            $part('registry'),
            $part('number'),
            $part('year'),
        );
    }


    /** @param array<mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new SyncException("Chybí povinná hodnota „{$key}“.");
        }
        return $value;
    }


    /** @param array<mixed> $data */
    private static function optionalText(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new SyncException("Hodnota „{$key}“ není text.");
        }
        return $value;
    }


    /** @param array<mixed> $data */
    private static function number(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new SyncException("Hodnota „{$key}“ není celé číslo.");
        }
        return $value;
    }


    /** @param array<mixed> $data */
    private static function optionalNumber(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new SyncException("Hodnota „{$key}“ není celé číslo.");
        }
        return $value;
    }


    /** @param array<mixed> $data */
    private static function flag(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new SyncException("Hodnota „{$key}“ není ano/ne.");
        }
        return $value;
    }


    /** @param array<mixed> $data */
    private static function moment(array $data, string $key): \DateTimeImmutable
    {
        $value = self::optionalMoment($data, $key);
        if ($value === null) {
            throw new SyncException("Chybí povinný čas „{$key}“.");
        }
        return $value;
    }


    /** @param array<mixed> $data */
    private static function optionalMoment(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new SyncException("Hodnota „{$key}“ není čas.");
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new SyncException("Hodnota „{$key}“ není platný čas.", previous: $e);
        }
    }
}
