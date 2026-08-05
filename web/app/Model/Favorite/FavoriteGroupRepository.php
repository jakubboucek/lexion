<?php declare(strict_types=1);

namespace App\Model\Favorite;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Manually ordered per-user groups of favorites (see migration 2026-07-20-00).
 * Group names are unique per user (uq_favorite_group_name); callers turn the
 * violation into a form error. Ownership checks are the caller's job.
 */
final readonly class FavoriteGroupRepository
{
    /** @var Hydrator<FavoriteGroup> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        private FavoriteRepository $favorites,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(FavoriteGroup::class);
    }


    /**
     * All groups of the user in manual order.
     *
     * @return list<FavoriteGroup>
     */
    public function findByUser(int $userId): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('favorite_group')
                ->where('user_id', $userId)
                ->order('position'),
        )->collectList();
    }


    public function getById(int $id): ?FavoriteGroup
    {
        $row = $this->db->table('favorite_group')->get($id);
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /**
     * Adds to the end of the user's group list. `position` is assigned here on
     * purpose, never taken from the caller: the 1..n ordering of the list is
     * this repository's invariant (see FavoriteRepository::add()).
     */
    public function add(FavoriteGroup $group): FavoriteGroup
    {
        return $this->transaction(function () use ($group): FavoriteGroup {
            $max = $this->db->table('favorite_group')->where('user_id', $group->userId)->max('position');
            $group->position = (int) $max + 1;
            $row = $this->db->table('favorite_group')->insert($this->hydrator->toData($group));
            assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
            return $this->hydrator->fromData($row);
        });
    }


    /** Patches the row with the initialized properties of $changes. */
    public function update(int $id, FavoriteGroup $changes): void
    {
        $this->db->table('favorite_group')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }


    /**
     * Removes the group; its favorites move to the end of the ungrouped
     * bucket first, atomically with the delete.
     */
    public function remove(FavoriteGroup $group): void
    {
        $this->transaction(function () use ($group): void {
            $this->favorites->ungroupAll($group->userId, $group->id);
            $this->delete($group);
        });
    }


    /** Deletes the group only; move its favorites out first (see remove()). */
    public function delete(FavoriteGroup $group): void
    {
        $this->transaction(function () use ($group): void {
            $this->db->table('favorite_group')->wherePrimary($group->id)->delete();
            $this->renumber($group->userId);
        });
    }


    /** Swaps the group with its neighbor; no-op at the list edge. */
    public function move(FavoriteGroup $group, int $direction): void
    {
        $this->transaction(function () use ($group, $direction): void {
            $row = $this->db->table('favorite_group')
                ->where('user_id', $group->userId)
                ->where('position ' . ($direction < 0 ? '<' : '>') . ' ?', $group->position)
                ->order('position ' . ($direction < 0 ? 'DESC' : 'ASC'))
                ->limit(1)
                ->fetch();
            if (!$row instanceof ActiveRow) {
                return;
            }
            $neighbor = $this->hydrator->fromData($row);
            $this->reposition($group->id, $neighbor->position);
            $this->reposition($neighbor->id, $group->position);
        });
    }


    private function renumber(int $userId): void
    {
        $position = 1;
        foreach ($this->findByUser($userId) as $group) {
            if ($group->position !== $position) {
                $this->reposition($group->id, $position);
            }
            $position++;
        }
    }


    private function reposition(int $id, int $position): void
    {
        $changes = new FavoriteGroup;
        $changes->position = $position;
        $this->update($id, $changes);
    }


    /**
     * Multi-step mutations run atomically; an interrupted renumbering would
     * leave duplicate/gapped positions. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return $this->db->getConnection()->transaction($callback);
    }
}
