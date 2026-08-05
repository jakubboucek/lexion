<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\Codelist\Court;


/**
 * Court candidates for a file number, as gathered by CourtCandidateService.
 * Candidates only ever suggest - they never constrain the court options and
 * never override a court the user picked by hand.
 */
final readonly class CourtCandidates
{
    /**
     * @param list<Court> $cachedCourts courts holding the case on record
     * @param list<array{court: Court, hearings: int}> $hearingCourts venue courts of recorded
     *     hearings (only gathered when the cache is silent - venue != home court)
     */
    public function __construct(
        public array $cachedCourts,
        public array $hearingCourts,
    ) {
    }


    /** The single court the evidence points at, if it is unambiguous. */
    public function sole(): ?Court
    {
        if (count($this->cachedCourts) === 1) {
            return $this->cachedCourts[0];
        }
        if ($this->cachedCourts === [] && count($this->hearingCourts) === 1) {
            return $this->hearingCourts[0]['court'];
        }
        return null;
    }
}
