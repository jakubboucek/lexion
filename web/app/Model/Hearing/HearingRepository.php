<?php declare(strict_types=1);

namespace App\Model\Hearing;

use App\Model\Spisovka\Spisovka;
use JakubBoucek\Hydrator\EntitySet;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Hearings harvested from infoJednani (see migrations 2026-07-26-00/01 and
 * docs/infojednani-api.md). A hearing knows the court of the ROOM it is held
 * in (the venue), which is only a candidate for the case's home court - the
 * link to `proceeding` carries the strength of that belief in `court_binding`.
 * This repository stays thin; callers interpret the entities.
 */
final readonly class HearingRepository
{
    /** @var Hydrator<Hearing> */
    private Hydrator $hydrator;

    /** @var Hydrator<HearingObservation> */
    private Hydrator $observations;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(Hearing::class);
        $this->observations = $hydrators->for(HearingObservation::class);
    }


    /**
     * Every hearing on record, as a lazy stream - the table has tens of
     * thousands of rows and the CLI tools walk it once to build their index.
     *
     * @return EntitySet<Hearing>
     */
    public function streamAll(): EntitySet
    {
        return $this->hydrator->fromDataSet($this->db->table('hearing'));
    }


    /**
     * Hearings whose court binding is still a guess - the working set of the
     * corroboration phase (see bin/hearing-bind.php).
     *
     * @return EntitySet<Hearing>
     */
    public function streamUnconfirmed(): EntitySet
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('hearing')->where('court_binding <> ?', CourtBinding::Confirmed->value),
        );
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(Hearing $hearing): Hearing
    {
        $row = $this->db->table('hearing')->insert($this->hydrator->toData($hearing));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /** Patches the row with the initialized properties of $changes. */
    public function update(int $id, Hearing $changes): void
    {
        $this->db->table('hearing')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }


    /**
     * Records a raw per-source observation of a hearing. INSERT IGNORE against
     * the (hearing, source, observed_at, room_key) unique makes re-imports
     * idempotent; returns true when a new row was actually written.
     */
    public function insertObservationIgnore(HearingObservation $observation): bool
    {
        $result = $this->db->query(
            'INSERT IGNORE INTO hearing_observation ?',
            $this->observations->toData($observation),
        );
        return $result->getRowCount() > 0;
    }


    /**
     * Venue courts where a hearing with the given file number is on record,
     * with the number of hearings, busiest first.
     *
     * Used to preselect the court on the homepage when the file number alone
     * is known. This is a weaker signal than the case files on record: it says a
     * hearing with that file number took place in that court's room, not that
     * the case is filed there. Never use it to constrain the court options.
     *
     * @return array<string, int> court kod => hearing count
     */
    public function countPerVenueBySpisovka(Spisovka $spisovka): array
    {
        $counts = $this->db->table('hearing')
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
