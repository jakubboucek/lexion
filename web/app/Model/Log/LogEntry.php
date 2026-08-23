<?php declare(strict_types=1);

namespace App\Model\Log;

use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Struct\JsonObject;


/**
 * One row of the application log (table `log`, migration 2026-08-22-01) -
 * either an instant record or a run. Written through LogService; no read
 * side yet (analysis by hand until the System UI exists). See
 * docs/logovani.md.
 *
 * The JSON columns are typed as JsonObject (whole-payload struct, hydrator
 * >= 0.7) - unlike the CaseFile payloads these are OUR structures, so there
 * is no verbatim snapshot to protect. Struct semantics: the property always
 * holds an instance, an empty payload is a NULL column and a NULL column
 * hydrates into an empty instance. JsonObject keeps null values inside the
 * document, which the `files` map relies on.
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
    public JsonObject $data;
    /** Auto-collected environment: origin web/cli, url/argv, ip, request id. */
    public JsonObject $context;
    /** Caller-provided outcome payload - "how it went" (e.g. an import report). */
    public JsonObject $resultData;
    /**
     * Runs only: meaning => filename map, relative to the log directory.
     * A null value = the channel existed but stayed empty and its file was
     * deleted at finish. Empty for instant records (and for a run that
     * opened no channels - a run tells itself apart by finished_at/status).
     */
    public JsonObject $files;
    /** Event time / run start. */
    public \DateTimeImmutable $occurredAt;
    /** Runs only; NULL with status Pending means running or crashed. */
    public ?\DateTimeImmutable $finishedAt;
}
