<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * One case file the import refused to merge. Not fatal: the case is left
 * exactly as it was and the import moves on to the next record.
 */
final readonly class SyncProblem
{
    public function __construct(
        /** Case identity as written in the file, e.g. `OSSEMOP 6 C 1/2023`. */
        public string $caseFile,
        public SyncProblemReason $reason,
        /** What exactly clashed, e.g. the event key - already language-neutral. */
        public ?string $detail = null,
    ) {
    }


    /** One line for the application log; the UI renders the reason itself. */
    public function logLine(): string
    {
        $reason = match ($this->reason) {
            SyncProblemReason::EventMissingInNewerSnapshot => 'event missing in the newer snapshot',
            SyncProblemReason::EventDateMismatch => 'paired events differ in date',
            SyncProblemReason::UnknownCodelistKey => 'unknown codelist key',
            SyncProblemReason::InvalidRecord => 'malformed record',
        };
        return "Sync import skipped {$this->caseFile}: {$reason}"
            . ($this->detail !== null ? " ({$this->detail})" : '');
    }
}
