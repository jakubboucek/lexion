<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\Codelist\CourtRepository;
use App\Model\Hearing\HearingRepository;
use App\Model\Proceeding\ProceedingRepository;


/**
 * Gathers court candidates for a file number without a court - the single
 * home of the rule shared by the homepage submit fallback and the live
 * validation endpoint (the client JS only renders what the endpoint returns).
 *
 * Order of evidence: the proceeding cache first (closest to the home court,
 * but never authoritative - the case may exist at courts the cache does not
 * know). Hearings fill in only when the cache is silent: they name the court
 * of the ROOM, not necessarily the home court, so UI texts must say "we have
 * hearings on record", never "the case is filed at".
 */
final readonly class CourtCandidateService
{
    public function __construct(
        private ProceedingRepository $proceedings,
        private HearingRepository $hearings,
        private CourtRepository $courts,
    ) {
    }


    public function candidatesFor(Spisovka $spisovka): CourtCandidates
    {
        $cached = [];
        $rows = $this->proceedings->findBySpisovka(
            $spisovka->registryNorm(),
            $spisovka->senate,
            $spisovka->number,
            $spisovka->year,
        );
        foreach ($rows as $row) {
            $court = $this->courts->getByKod((string) $row->court_kod);
            if ($court !== null) {
                $cached[] = $court;
            }
        }

        $hearingCourts = [];
        if ($cached === []) {
            $counts = $this->hearings->countPerVenueBySpisovka(
                $spisovka->registryNorm(),
                $spisovka->senate,
                $spisovka->number,
                $spisovka->year,
            );
            foreach ($counts as $kod => $count) {
                $court = $this->courts->getByKod((string) $kod);
                if ($court !== null) {
                    $hearingCourts[] = ['court' => $court, 'hearings' => $count];
                }
            }
        }

        return new CourtCandidates($cached, $hearingCourts);
    }
}
