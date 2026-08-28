<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * One unit of work the scheduler hands out: a case number to test, the phase
 * it belongs to (bulk_fill = filling the known range below the end,
 * end_search = finding/confirming the end) and how it was chosen. Fed to the
 * decision log verbatim.
 */
final readonly class SeriesScanWork
{
    public function __construct(
        public int $number,
        public string $phase,
        public string $method,
    ) {
    }
}
