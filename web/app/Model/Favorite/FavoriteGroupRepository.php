<?php declare(strict_types=1);

namespace App\Model\Favorite;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


/**
 * Manually ordered per-user groups of favorites (see migration 2026-07-20-00).
 * Group names are unique per user (uq_favorite_group_name); callers turn the
 * violation into a form error. Ownership checks are the caller's job.
 */
final readonly class FavoriteGroupRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    /** All groups of the user in manual order. */
    public function findByUser(int $userId): Selection
    {
        return $this->explorer->table('favorite_group')
            ->where('user_id', $userId)
            ->order('position');
    }


    public function getById(int $id): ?ActiveRow
    {
        return $this->explorer->table('favorite_group')->get($id);
    }


    /** Adds to the end of the user's group list. */
    public function add(int $userId, string $name): ActiveRow
    {
        $max = $this->explorer->table('favorite_group')->where('user_id', $userId)->max('position');
        $row = $this->explorer->table('favorite_group')->insert([
            'user_id' => $userId,
            'name' => $name,
            'position' => (int) $max + 1,
        ]);
        assert($row instanceof ActiveRow);
        return $row;
    }


    public function update(int $id, array $data): void
    {
        $this->explorer->table('favorite_group')->wherePrimary($id)->update($data);
    }


    /** Deletes the group only; move its favorites out first (ungroupAll). */
    public function delete(ActiveRow $group): void
    {
        $userId = (int) $group->user_id;
        $this->explorer->table('favorite_group')->wherePrimary((int) $group->id)->delete();
        $this->renumber($userId);
    }


    /** Swaps the group with its neighbor; no-op at the list edge. */
    public function move(ActiveRow $group, int $direction): void
    {
        $neighbor = $this->explorer->table('favorite_group')
            ->where('user_id', (int) $group->user_id)
            ->where('position ' . ($direction < 0 ? '<' : '>') . ' ?', (int) $group->position)
            ->order('position ' . ($direction < 0 ? 'DESC' : 'ASC'))
            ->limit(1)
            ->fetch();
        if (!$neighbor instanceof ActiveRow) {
            return;
        }
        $position = (int) $group->position;
        $this->update((int) $group->id, ['position' => (int) $neighbor->position]);
        $this->update((int) $neighbor->id, ['position' => $position]);
    }


    private function renumber(int $userId): void
    {
        $position = 1;
        foreach ($this->findByUser($userId) as $row) {
            if ((int) $row->position !== $position) {
                $this->update((int) $row->id, ['position' => $position]);
            }
            $position++;
        }
    }
}
