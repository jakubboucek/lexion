<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * Outcome of scanning one block, for the CLI summary and the run's result
 * payload. `confirmedEnd` is null when the end stayed unconfirmed (then
 * `unconfirmedReason` says why: hit_ceiling / interrupted / dry_run).
 */
final readonly class SeriesScanResult
{
    public function __construct(
        public string $label,
        public ?int $confirmedEnd,
        public ?string $unconfirmedReason,
        public int $requests,
        public int $hits,
        public int $misses,
    ) {
    }


    /** @return array<string, mixed> */
    public function toLogData(): array
    {
        return [
            'series' => $this->label,
            'confirmedEnd' => $this->confirmedEnd,
            'unconfirmedReason' => $this->unconfirmedReason,
            'requests' => $this->requests,
            'hits' => $this->hits,
            'misses' => $this->misses,
        ];
    }
}
