<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Entity;


/**
 * One recorded data-loss anomaly of a case file (table `case_file_journal`,
 * migration 2026-08-22-00). Written by CaseFileJournalService, read by nobody
 * yet - the journal is evidence first, UI later.
 *
 * The snapshots are full JSON states of the case (case_file row + all its
 * event and relation rows) serialized by the Hydrator Json format, so the
 * moment of the anomaly stays fully reconstructible even after further
 * changes to the live rows. They stay raw strings here for the same reason
 * the per-source payloads of CaseFile do.
 */
class CaseFileJournalEntry implements Entity
{
    public int $id;
    /** NULL only when the anomaly has no local case row (refused response of a never-stored case). */
    public ?int $caseFileId;
    public JournalEntryType $type;
    public \DateTimeImmutable $occurredAt;
    public ?string $stateBefore;
    /** NULL when the operation wrote nothing (a refusal - nothing changed). */
    public ?string $stateAfter;
    /** What the snapshots cannot carry: refused payloads, the list of destructive operations. */
    public ?string $context;
    public \DateTimeImmutable $createdAt;
}
