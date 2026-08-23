<?php declare(strict_types=1);

namespace App\Model\Integrity;


/** Outcome of one executed check. */
final readonly class IntegrityCheckResult
{
    /** @param list<string> $samples */
    public function __construct(
        public IntegrityCheck $check,
        public int $count,
        public array $samples,
    ) {
    }


    /** A discrepancy with a nonzero count - the only red state there is. */
    public function isDefect(): bool
    {
        return $this->check->category === IntegrityCategory::Discrepancy && $this->count > 0;
    }
}
