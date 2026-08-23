<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Struct\JsonObject;


/**
 * One recorded data-loss anomaly of a case file (table `case_file_journal`,
 * migration 2026-08-22-00). Written by CaseFileJournalService, read by nobody
 * yet - the journal is evidence first, UI later.
 *
 * The snapshots are full JSON states of the case (case_file row + all its
 * event and relation rows) serialized by the Hydrator Json format, so the
 * moment of the anomaly stays fully reconstructible even after further
 * changes to the live rows. Typed as JsonObject (these are OUR structures,
 * no verbatim snapshot to protect - the raw payload columns of the case are
 * embedded as string values and stay byte-exact inside): an empty instance
 * is a NULL column and vice versa. A stored snapshot is never empty - it
 * always carries at least the caseFile key.
 */
class CaseFileJournalEntry implements Entity
{
    public int $id;
    /** NULL only when the anomaly has no local case row (refused response of a never-stored case). */
    public ?int $caseFileId;
    public JournalEntryType $type;
    public \DateTimeImmutable $occurredAt;
    /** Empty only when the anomaly has no local case row to capture. */
    public JsonObject $stateBefore;
    /** Empty when the operation wrote nothing (a refusal - nothing changed). */
    public JsonObject $stateAfter;
    /** What the snapshots cannot carry: refused payloads, the list of destructive operations. */
    public JsonObject $context;
    public \DateTimeImmutable $createdAt;
}
