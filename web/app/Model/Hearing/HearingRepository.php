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
 * link to `case_file` carries the strength of that belief in `court_binding`.
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


    /**
     * Ids of all hearings in ascending order - the sync export slices them
     * into parts and streams each part by its id range, so it never pages
     * with OFFSET over a table that may change under it.
     *
     * @return list<int>
     */
    public function allIds(): array
    {
        $ids = $this->db->table('hearing')->order('id')->fetchPairs(null, 'id');
        return array_map(intval(...), array_values($ids));
    }


    /**
     * Hearings by id, keyed by id - one query per export round.
     *
     * @param list<int> $ids
     * @return array<int, Hearing>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var array<int, Hearing> keyed by the int property `id` */
        $hearings = $this->hydrator
            ->fromDataSet($this->db->table('hearing')->where('id', $ids), keyBy: 'id')
            ->collectMap();
        return $hearings;
    }


    /**
     * One hearing by its natural identity - venue court, case and the minute
     * it starts. The sync reads the five parts off the wire and has no id to
     * look one up by.
     */
    public function getByIdentity(
        string $venueCourtKod,
        string $registryNorm,
        int $senate,
        int $bcNumber,
        int $year,
        \DateTimeInterface $date,
        \DateTimeInterface $time,
    ): ?Hearing
    {
        $row = $this->db->table('hearing')
            ->where('venue_court_kod', $venueCourtKod)
            ->where('registry_norm', $registryNorm)
            ->where('senate', $senate)
            ->where('bc_number', $bcNumber)
            ->where('year', $year)
            ->where('hearing_date', $date->format('Y-m-d'))
            ->where('hearing_time', $time->format('H:i:s'))
            ->fetch();
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /**
     * Raw observations of several hearings at once, grouped by hearing id -
     * one query per export round instead of one per hearing.
     *
     * @param list<int> $hearingIds
     * @return array<int, list<HearingObservation>> hearings without observations are absent
     */
    public function findObservationsByHearings(array $hearingIds): array
    {
        if ($hearingIds === []) {
            return [];
        }
        $grouped = [];
        $rows = $this->observations->fromDataSet(
            $this->db->table('hearing_observation')
                ->where('hearing_id', $hearingIds)
                ->order('hearing_id, observed_at, id'),
        );
        foreach ($rows as $observation) {
            $grouped[$observation->hearingId][] = $observation;
        }
        return $grouped;
    }


    /**
     * Points hearings at a room row that did not exist when they were stored.
     * A hearing keeps the verbatim room label and tolerates a NULL room_id on
     * purpose, so the sync may import hearings before their rooms; this closes
     * the gap once the codelist row arrives. Returns the number of rows fixed.
     */
    public function linkRoom(int $roomId, string $courtKod, string $label): int
    {
        return $this->db->table('hearing')
            ->where('venue_court_kod', $courtKod)
            ->where('room', $label)
            ->where('room_id IS NULL')
            ->update(['room_id' => $roomId]);
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
