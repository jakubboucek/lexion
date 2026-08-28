<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * One number the end-search wants tested, plus how it was chosen (for the
 * decision log). `method` is one of: estimate (first jump to the hint),
 * gallop (doubling reach), bisect (halving a bracket), confirm (contiguous
 * miss-run to tell a hole from the end).
 */
final readonly class SeriesProbe
{
    public function __construct(
        public int $number,
        public string $method,
    ) {
    }
}
