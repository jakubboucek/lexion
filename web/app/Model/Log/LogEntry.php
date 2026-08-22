<?php declare(strict_types=1);

namespace App\Model\Log;

use JakubBoucek\Hydrator\Entity;


/**
 * One row of the application log (table `log`, migration 2026-08-22-01) -
 * either an instant record or a run. Written through LogService; no read
 * side yet (analysis by hand until the System UI exists). See
 * docs/navrh-logovani.md.
 *
 * The JSON columns stay raw strings here, the same choice as the payload
 * columns of CaseFile: LogService encodes them and nothing decodes them yet.
 */
class LogEntry implements Entity
{
    public int $id;
    public string $resource;
    public string $action;
    /** Acted-on entity: an id, a slug, a filename... */
    public ?string $target;
    public LogStatus $status;
    /** Short machine-readable outcome ('aborted', ...). */
    public ?string $result;
    /** Human-readable text payload. */
    public ?string $message;
    /** Initiating user; NULL for CLI and system records. Deliberately no FK. */
    public ?int $userId;
    /** Caller-provided payload of the start - "what I ran with". */
    public ?string $data;
    /** Auto-collected environment: origin web/cli, url/argv, ip, request id. */
    public ?string $context;
    /** Caller-provided outcome payload - "how it went" (e.g. an import report). */
    public ?string $resultData;
    /**
     * Runs only: meaning => filename map, relative to the log directory.
     * A NULL value = the channel existed but stayed empty and its file was
     * deleted at finish.
     */
    public ?string $files;
    /** Event time / run start. */
    public \DateTimeImmutable $occurredAt;
    /** Runs only; NULL with status Pending means running or crashed. */
    public ?\DateTimeImmutable $finishedAt;
}
