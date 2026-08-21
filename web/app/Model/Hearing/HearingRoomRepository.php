<?php declare(strict_types=1);

namespace App\Model\Hearing;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Codelist of hearing rooms (see migration 2026-07-26-01). The label is the
 * only identifier infoJednani offers, so the key is (court_kod, label);
 * first_seen/last_seen/retired_at track the codelist life cycle (the retire
 * logic itself does not exist yet - see docs/infojednani-api.md TODO).
 */
final readonly class HearingRoomRepository
{
    /** @var Hydrator<HearingRoom> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(HearingRoom::class);
    }


    /** @return list<HearingRoom> */
    public function findAll(): array
    {
        return $this->hydrator
            ->fromDataSet($this->db->table('hearing_room'))
            ->collectList();
    }


    /** One room by its (court, label) identity - the only key the source offers. */
    public function getByKey(string $courtKod, string $label): ?HearingRoom
    {
        $row = $this->db->table('hearing_room')
            ->where('court_kod', $courtKod)
            ->where('label', $label)
            ->fetch();
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /** Patches the row with the initialized properties of $changes. */
    public function update(int $id, HearingRoom $changes): void
    {
        $this->db->table('hearing_room')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(HearingRoom $room): HearingRoom
    {
        $row = $this->db->table('hearing_room')->insert($this->hydrator->toData($room));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /**
     * Marks the room as present in the current codelist snapshot: a patch of
     * the two life-cycle columns, so an entity with just those set writes
     * exactly them - retiredAt explicitly back to null, the room is offered
     * upstream again.
     */
    public function touchSeen(int $id, \DateTimeImmutable $at): void
    {
        $changes = new HearingRoom;
        $changes->lastSeen = $at;
        $changes->retiredAt = null;

        $this->db->table('hearing_room')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }
}
