<?php declare(strict_types=1);

namespace App\Model\Log;

use Nette\Utils\Json;


/**
 * A run being prepared. LogService::createRunSession() already carries all
 * the basic facts; the session only registers files through the typed methods
 * (the writer type follows from the method called, so static analysis can
 * tell them apart) and start() then writes the pending DB row, opens the
 * files and hands over the LogRun to finish. Nothing runs and nothing is
 * written before start().
 */
final class LogRunSession
{
    /** @var array<string, LogRunFile> */
    private array $files = [];

    private bool $started = false;

    private readonly string $filePrefix;


    /**
     * @param array<mixed>|null $data
     * @internal use LogService::createRunSession()
     */
    public function __construct(
        private readonly LogRepository $repository,
        private readonly LogContextProvider $contextProvider,
        private readonly string $logDir,
        private readonly LogEventKind $kind,
        private readonly ?string $target,
        private readonly ?array $data,
    ) {
        // File names carry a random suffix instead of the DB id: they must be
        // known before the INSERT so the row can list them - the map in the
        // `files` column is the authoritative link, not the name.
        $this->filePrefix = sprintf(
            'run-%s-%s-%s-%s',
            new \DateTimeImmutable()->format('YmdHis'),
            self::fileNamePart($kind->resource()),
            self::fileNamePart($kind->value),
            bin2hex(random_bytes(3)),
        );
    }


    /** Registers a text channel: timestamped greppable lines. */
    public function textFile(string|LogRunChannel $meaning): LogRunTextFile
    {
        $meaning = $this->newMeaning($meaning);
        return $this->files[$meaning] = new LogRunTextFile(...$this->filePaths($meaning, 'log'));
    }


    /** Registers a JSONL channel: one structured record per line. */
    public function jsonlFile(string|LogRunChannel $meaning): LogRunJsonlFile
    {
        $meaning = $this->newMeaning($meaning);
        return $this->files[$meaning] = new LogRunJsonlFile(...$this->filePaths($meaning, 'jsonl'));
    }


    /** Writes the pending DB row, opens the files and starts the run. */
    public function start(): LogRun
    {
        if ($this->started) {
            throw new \LogicException('The run has already been started.');
        }
        $this->started = true;

        $entry = new LogEntry;
        $entry->resource = $this->kind->resource();
        $entry->action = $this->kind->value;
        $entry->target = $this->target;
        $entry->status = LogStatus::Pending;
        $entry->result = null;
        $entry->message = null;
        $entry->userId = $this->contextProvider->userId();
        $entry->data = $this->data !== null ? Json::encode($this->data) : null;
        $entry->context = Json::encode($this->contextProvider->context());
        $entry->resultData = null;
        $entry->files = Json::encode(array_map(static fn(LogRunFile $file) => $file->fileName, $this->files));
        $entry->occurredAt = new \DateTimeImmutable;
        $entry->finishedAt = null;
        $stored = $this->repository->insert($entry);

        foreach ($this->files as $file) {
            $file->open();
        }
        return new LogRun($this->repository, $stored->id, $this->files);
    }


    private function newMeaning(string|LogRunChannel $meaning): string
    {
        if ($this->started) {
            throw new \LogicException('The run has already been started, its files are fixed.');
        }
        $meaning = $meaning instanceof LogRunChannel ? $meaning->value : $meaning;
        // The meaning is both a map key and a filename part - keep it plain.
        if (preg_match('~^[a-z0-9][a-z0-9_-]*$~D', $meaning) !== 1) {
            throw new \InvalidArgumentException("Invalid file meaning '{$meaning}' - use lowercase letters, digits, '-' and '_'.");
        }
        if (isset($this->files[$meaning])) {
            throw new \InvalidArgumentException("File meaning '{$meaning}' is already registered.");
        }
        return $meaning;
    }


    /** @return array{fileName: string, path: string} */
    private function filePaths(string $meaning, string $extension): array
    {
        $fileName = "{$this->filePrefix}-{$meaning}.{$extension}";
        return ['fileName' => $fileName, 'path' => $this->logDir . '/' . $fileName];
    }


    private static function fileNamePart(string $value): string
    {
        return trim((string) preg_replace('~[^a-z0-9-]+~', '-', strtolower($value)), '-');
    }
}
