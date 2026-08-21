<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * One record the import refused to merge. Not fatal: whatever it describes is
 * left exactly as it was and the import moves on to the next record.
 */
final readonly class SyncProblem
{
    public function __construct(
        /**
         * What was skipped, in the identity the file gave it - a case file
         * number, a hearing's court/case/time, a room's court and label.
         */
        public string $subject,
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
        return "Sync import skipped {$this->subject}: {$reason}"
            . ($this->detail !== null ? " ({$this->detail})" : '');
    }
}
