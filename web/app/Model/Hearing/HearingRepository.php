<?php declare(strict_types=1);

namespace App\Model\Hearing;

use App\Model\Spisovka\Spisovka;
use Nette\Database\Explorer;
use Nette\Database\Table\Selection;


/**
 * Hearings harvested from infoJednani (see migrations 2026-07-26-00/01 and
 * docs/infojednani-api.md). A hearing knows the court of the ROOM it is held
 * in (the venue), which is only a candidate for the case's home court - the
 * link to `proceeding` carries the strength of that belief in `court_binding`.
 * This repository stays thin; callers interpret the rows.
 */
final readonly class HearingRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    public function findAll(): Selection
    {
        return $this->explorer->table('hearing');
    }


    /**
     * Venue courts where a hearing with the given file number is on record,
     * with the number of hearings, busiest first.
     *
     * Used to preselect the court on the homepage when the file number alone
     * is known. This is a weaker signal than the proceeding cache: it says a
     * hearing with that file number took place in that court's room, not that
     * the case is filed there. Never use it to constrain the court options.
     *
     * @return array<string, int> court kod => hearing count
     */
    public function countPerVenueBySpisovka(Spisovka $spisovka): array
    {
        $counts = $this->explorer->table('hearing')
            ->select('venue_court_kod, COUNT(*) AS cnt')
            ->where('registry_norm', $spisovka->registryNorm())
            ->where('senate', $spisovka->senate)
            ->where('bc_number', $spisovka->number)
            ->where('year', $spisovka->year)
            ->group('venue_court_kod')
            ->order('cnt DESC')
            ->fetchPairs('venue_court_kod', 'cnt');
        return array_map(intval(...), $counts);
    }
}
