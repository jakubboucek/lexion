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


    /**
     * One record for the problems file of the import run; the UI renders the
     * reason itself.
     *
     * @return array{subject: string, reason: string, detail: string|null}
     */
    public function toLogData(): array
    {
        return [
            'subject' => $this->subject,
            'reason' => $this->reason->name,
            'detail' => $this->detail,
        ];
    }
}
