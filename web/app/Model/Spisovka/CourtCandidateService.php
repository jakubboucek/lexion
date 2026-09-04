<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\CaseFile\CaseFileRepository;
use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\SenateRule;
use App\Model\Codelist\SenateRuleRepository;
use App\Model\Hearing\HearingRepository;


/**
 * Gathers court candidates for a file number without a court - the single
 * home of the rule shared by the homepage submit fallback and the live
 * validation endpoint (the client JS only renders what the endpoint returns).
 *
 * Order of evidence: the case files on record first (closest to the home
 * court), then venue courts of recorded hearings (they name the court of the
 * ROOM, not necessarily the home court, so UI texts must say "nařízené
 * jednání", never "the case is filed at"), and finally courts whose work
 * schedule lists the senate as active. The tiers are disjoint - a court shows
 * up only in the strongest tier it qualifies for. None of them is
 * authoritative (the schedules are known to be incomplete), which is why
 * candidates only suggest and never constrain the court options.
 */
final readonly class CourtCandidateService
{
    public function __construct(
        private CaseFileRepository $caseFiles,
        private HearingRepository $hearings,
        private SenateRuleRepository $senateRules,
        private CourtRepository $courts,
    ) {
    }


    public function candidatesFor(Spisovka $spisovka): CourtCandidates
    {
        $seen = [];

        $cached = [];
        foreach ($this->caseFiles->findBySpisovka($spisovka) as $case) {
            $court = $this->courts->getByKod($case->courtKod);
            if ($court !== null && !isset($seen[(string) $court->kod])) {
                $cached[] = $court;
                $seen[(string) $court->kod] = true;
            }
        }

        // Ordered by hearing count - the busiest venue first.
        $hearingCourts = [];
        foreach ($this->hearings->countPerVenueBySpisovka($spisovka) as $kod => $count) {
            $court = $this->courts->getByKod((string) $kod);
            if ($court !== null && !isset($seen[(string) $court->kod])) {
                $hearingCourts[] = ['court' => $court, 'hearings' => $count];
                $seen[(string) $court->kod] = true;
            }
        }

        $senateCourts = [];
        $rules = $this->senateRules->findRules($spisovka->registryNorm(), $spisovka->senate);
        foreach (array_unique(array_map(static fn(SenateRule $rule): string => $rule->courtKod, $rules)) as $kod) {
            $court = $this->courts->getByKod($kod);
            if ($court !== null && !isset($seen[(string) $court->kod])) {
                $senateCourts[] = $court;
                $seen[(string) $court->kod] = true;
            }
        }
        $collator = new \Collator('cs_CZ');
        usort($senateCourts, static fn(Court $a, Court $b): int => $collator->compare($a->name, $b->name));

        return new CourtCandidates($cached, $hearingCourts, $senateCourts);
    }
}
