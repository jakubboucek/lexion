<?php declare(strict_types=1);

namespace App\Model\Sync;

use App\Model\Log\LogRunChannel;
use App\Model\Log\LogRunJsonlFile;
use App\Model\Log\LogRunTextFile;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * Reads the receiving side of a sync: walks a JSONL file (see SyncFormat) and
 * hands each record to the domain that owns it. The merging itself lives in
 * CaseFileMergeService and HearingMergeService; what holds for all of it:
 *
 * THE MERGE IS ADDITIVE. Nothing is ever deleted and no value is ever
 * replaced by an older one, which buys three properties the whole design
 * leans on: applying the same file twice changes nothing, applying files in
 * any order gives the same result, and a stale file can do no harm. That is
 * why parts may be uploaded in any order and a half-finished import can
 * simply be repeated.
 *
 * FRESHNESS IS DOMAIN FRESHNESS, never `updated_at` - see Freshness.
 *
 * A BAD RECORD COSTS ONE RECORD. Whatever cannot be merged safely is left
 * exactly as it was, reported and written to the run's problems file, and the
 * run continues. Only a file that is not ours, is of another format version,
 * or breaks apart mid-line stops the import outright.
 *
 * CODELIST DIFFERENCES ARE WARNINGS. A row that differs in content cannot
 * corrupt anything - it only drives URLs and display - so the comparison is
 * reported, never a veto. A key the data points at and this side lacks is a
 * different matter, and each domain checks that for its own records.
 *
 * EVERY IMPORT IS A LOGGED RUN (docs/logovani.md): progress goes to the
 * out channel, every skipped record to the problems channel, and the finished
 * row keeps the whole report as its result payload - so the counts outlive
 * the HTTP response the operator saw.
 */
final readonly class SyncImportService
{
    /** One progress line per this many records - enough to see where a crashed run was. */
    private const int ProgressEvery = 1000;


    public function __construct(
        private SyncCodelistService $codelists,
        private CaseFileMergeService $caseFiles,
        private HearingMergeService $hearings,
        private LogService $log,
    ) {
    }


    /**
     * @param string|null $fileName original name of the uploaded file, for the run record
     * @throws SyncException the file is unreadable, incompatible or malformed
     */
    public function import(string $path, ?string $fileName = null): SyncImportReport
    {
        $session = $this->log->buildRunSession(SyncLogKind::Import, target: $fileName);
        $out = $session->textFile(LogRunChannel::Out);
        $problems = $session->jsonlFile('problems');
        $run = $session->start();

        try {
            $report = $this->process($path, $out, $problems);
        } catch (\Throwable $e) {
            $run->finish(
                LogStatus::Failed,
                result: $e instanceof SyncException ? 'refused' : 'error',
                message: $e->getMessage(),
            );
            throw $e;
        }
        $run->finish(LogStatus::Ok, resultData: $report->toLogData());
        return $report;
    }


    private function process(string $path, LogRunTextFile $out, LogRunJsonlFile $problems): SyncImportReport
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
            $record = $this->readHeader($handle, $report, $out);

            $processed = 0;
            while ($record !== null) {
                $processed++;
                try {
                    match ($record->type()) {
                        RecordType::CaseFile => $this->caseFiles->merge($record, $report, $problems),
                        RecordType::HearingRoom => $this->hearings->mergeRoom($record, $report, $problems),
                        RecordType::Hearing => $this->hearings->mergeHearing($record, $report, $problems),
                        default => throw new SyncException('Soubor obsahuje neznámý nebo neočekávaný typ záznamu.'),
                    };
                } catch (\Throwable $e) {
                    // The last line of the file must identify what the run
                    // died on - that is what the file exists for.
                    $type = $record->type()->value ?? 'unknown';
                    $out->writeLine("failed at record #{$processed} ({$type}): {$e->getMessage()}");
                    throw $e;
                }
                if ($processed % self::ProgressEvery === 0) {
                    $out->writeLine("processed {$processed} records");
                }
                $record = self::nextRecord($handle);
            }

            $out->writeLine(sprintf(
                'done: %d records (%d case files, %d hearings, %d rooms, %d problems)',
                $processed,
                $report->caseFilesTotal(),
                $report->hearingsTotal(),
                $report->roomsTotal(),
                $report->problemsTotal,
            ));
            return $report;
        } finally {
            gzclose($handle);
        }
    }


    /**
     * The mandatory first line: says whose file this is and which format
     * version wrote it. Anything else means we are not reading a sync file at
     * all, so nothing beyond it is even attempted.
     */
    private function readMeta(?SyncRecord $record, SyncImportReport $report): void
    {
        if ($record === null
            || $record->type() !== RecordType::Meta
            || $record->raw('format') !== SyncFormat::Format
        ) {
            throw new SyncException('Tohle není synchronizační soubor Lexionu (chybí úvodní hlavička).');
        }

        $version = $record->raw('version');
        if ($version !== SyncFormat::Version) {
            throw new SyncException(sprintf(
                'Soubor je ve formátu verze %s, tahle instalace čte verzi %d. Sjednoťte verzi kódu na obou stranách.',
                is_scalar($version) ? (string) $version : '?',
                SyncFormat::Version,
            ));
        }

        $dataset = $record->raw('dataset');
        $report->dataset = is_string($dataset) ? SyncDataset::tryFrom($dataset) : null;
        $report->origin = $record->optionalText('origin');
        $report->generatedAt = $record->optionalMoment('generatedAt');
        $report->part = $record->optionalNumber('part') ?? 1;
        $report->parts = $record->optionalNumber('parts') ?? 1;
    }


    /**
     * Reads the whole header - the meta line and the codelist records that
     * follow it - and returns the first data record, or null for a file that
     * carries none. Codelist differences are recorded on the report and
     * logged; they never stop the import.
     *
     * @param resource $handle
     */
    private function readHeader($handle, SyncImportReport $report, LogRunTextFile $out): ?SyncRecord
    {
        $this->readMeta(self::nextRecord($handle), $report);
        $out->writeLine(sprintf(
            'header: dataset=%s part=%d/%d origin=%s generatedAt=%s',
            $report->dataset->value ?? '?',
            $report->part,
            $report->parts,
            $report->origin ?? '?',
            $report->generatedAt?->format('Y-m-d H:i:s') ?? '?',
        ));

        $codelists = [];
        $first = null;
        while (($record = self::nextRecord($handle)) !== null) {
            if ($record->type() !== RecordType::Codelist) {
                $first = $record;
                break;
            }
            $rows = $record->raw('rows');
            if (!is_array($rows)) {
                throw new SyncException('Záznam s číselníkem je poškozený.');
            }
            $codelists[$record->text('codelist')] = $rows;
        }

        if ($codelists === []) {
            throw new SyncException('V souboru chybí záznamy s číselníky.');
        }
        $report->codelistDifferences = $this->codelists->compare($codelists);
        $this->logCodelistDifferences($report, $out);

        return $first;
    }


    /** One line per drifted codelist - a line per row would flood the log. */
    private function logCodelistDifferences(SyncImportReport $report, LogRunTextFile $out): void
    {
        $perCodelist = [];
        foreach ($report->codelistDifferences as $difference) {
            $perCodelist[$difference->codelist] = ($perCodelist[$difference->codelist] ?? 0) + 1;
        }
        foreach ($perCodelist as $codelist => $count) {
            $out->writeLine("codelist {$codelist} differs from the file in {$count} row(s)");
        }
    }


    /**
     * Next non-empty record of the file, or null at the end.
     *
     * @param resource $handle
     */
    private static function nextRecord($handle): ?SyncRecord
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
            return new SyncRecord($record);
        }
        return null;
    }
}
