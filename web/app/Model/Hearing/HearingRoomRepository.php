<?php declare(strict_types=1);

namespace App\Model\Hearing;

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
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    /** @return list<ActiveRow> */
    public function findAll(): array
    {
        return array_values($this->explorer->table('hearing_room')->fetchAll());
    }


    public function insert(array $data): ActiveRow
    {
        $row = $this->explorer->table('hearing_room')->insert($data);
        assert($row instanceof ActiveRow);
        return $row;
    }


    /** Marks the room as present in the current codelist snapshot. */
    public function touchSeen(int $id, \DateTimeInterface $at): void
    {
        $this->explorer->table('hearing_room')->wherePrimary($id)->update([
            'last_seen' => $at,
            'retired_at' => null,
        ]);
    }
}
