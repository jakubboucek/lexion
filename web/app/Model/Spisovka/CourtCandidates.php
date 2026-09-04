<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\Codelist\Court;


/**
 * Court candidates for a file number, as gathered by CourtCandidateService.
 * Candidates only ever suggest - they never constrain the court options and
 * never override a court the user picked by hand.
 *
 * Three tiers of evidence, strongest first: case files on record, recorded
 * hearings, and senate rules mined from the courts' work schedules. The tiers
 * are disjoint - a court appears only in the strongest tier it qualifies for.
 */
final readonly class CourtCandidates
{
    /**
     * @param list<Court> $cachedCourts courts holding the case on record
     * @param list<array{court: Court, hearings: int}> $hearingCourts venue courts of recorded
     *     hearings (venue != necessarily the home court)
     * @param list<Court> $senateCourts courts whose work schedule lists the senate as active -
     *     the weakest tier: the schedules are known to be incomplete
     */
    public function __construct(
        public array $cachedCourts,
        public array $hearingCourts,
        public array $senateCourts,
    ) {
    }


    /**
     * The court to preselect: the strongest non-empty tier decides, and only
     * when it is unambiguous. A weaker tier never breaks a stronger tier's
     * tie - two case files on record mean a genuine choice, however clear
     * the schedules are.
     */
    public function preselected(): ?Court
    {
        if ($this->cachedCourts !== []) {
            return count($this->cachedCourts) === 1 ? $this->cachedCourts[0] : null;
        }
        if ($this->hearingCourts !== []) {
            return count($this->hearingCourts) === 1 ? $this->hearingCourts[0]['court'] : null;
        }
        return count($this->senateCourts) === 1 ? $this->senateCourts[0] : null;
    }
}
