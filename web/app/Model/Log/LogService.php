<?php declare(strict_types=1);

namespace App\Model\Log;

use JakubBoucek\Hydrator\Struct\JsonObject;


/**
 * The application log: table `log` plus run files in the log directory (see
 * docs/logovani.md). Two record kinds - log() writes an instant record
 * atomically in its final state; buildRunSession() prepares a run: a pending
 * row at start, progress into append-only files, one finishing UPDATE.
 *
 * Not to be confused with Tracy (exceptions and low-level problems of the
 * application) or case_file_journal (data-loss evidence per case file).
 */
final readonly class LogService
{
    public function __construct(
        private LogRepository $repository,
        private LogContextProvider $contextProvider,
        private string $logDir,
    ) {
    }


    /**
     * Writes one immutable record. $status is final here - Pending belongs
     * to runs.
     *
     * @param array<mixed>|null $data
     */
    public function log(
        LogEventKind $kind,
        LogStatus $status = LogStatus::Ok,
        ?string $target = null,
        ?string $result = null,
        ?string $message = null,
        ?array $data = null,
    ): LogEntry
    {
        return $this->logRaw($kind->resource(), $kind->value, $status, $target, $result, $message, $data);
    }


    /**
     * Escape hatch of log() for the rare event no LogEventKind enum fits.
     *
     * @param array<mixed>|null $data
     */
    public function logRaw(
        string $resource,
        string $action,
        LogStatus $status = LogStatus::Ok,
        ?string $target = null,
        ?string $result = null,
        ?string $message = null,
        ?array $data = null,
    ): LogEntry
    {
        if ($status === LogStatus::Pending) {
            throw new \InvalidArgumentException('An instant record cannot be pending - use buildRunSession() for runs.');
        }
        $entry = new LogEntry;
        $entry->resource = $resource;
        $entry->action = $action;
        $entry->target = $target;
        $entry->status = $status;
        $entry->result = $result;
        $entry->message = $message;
        $entry->userId = $this->contextProvider->userId();
        $entry->data = JsonObject::fromArray($data ?? []);
        $entry->context = JsonObject::fromArray($this->contextProvider->context());
        $entry->resultData = new JsonObject;
        $entry->files = new JsonObject;
        $entry->occurredAt = new \DateTimeImmutable;
        $entry->finishedAt = null;
        return $this->repository->insert($entry);
    }


    /**
     * Prepares a run - nothing is written or opened before the session's
     * start(). The name deliberately avoids "run": creating a session
     * executes nothing.
     *
     * @param array<mixed>|null $data
     */
    public function buildRunSession(
        LogEventKind $kind,
        ?string $target = null,
        ?array $data = null,
    ): LogRunBuilder
    {
        return new LogRunBuilder($this->repository, $this->contextProvider, $this->logDir, $kind, $target, $data);
    }
}
