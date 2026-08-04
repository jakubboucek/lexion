<?php declare(strict_types=1);

namespace App\Model\Favorite;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


/**
 * Per-user favorite proceedings (see migration 2026-07-20-00). Rows are
 * manually ordered per bucket - a group, or the ungrouped section
 * (group_id NULL) - and buckets are renumbered 1..n after every mutation.
 * Ownership checks are the caller's job; methods taking an id trust it.
 */
final readonly class FavoriteRepository
{
    public function __construct(
        private Explorer $db,
    ) {
    }


    /**
     * All favorites of the user, bucket by bucket in manual order.
     *
     * @return list<ActiveRow>
     */
    public function findByUser(int $userId): array
    {
        return array_values(
            $this->db->table('favorite')
                ->where('user_id', $userId)
                ->order('group_id, position')
                ->fetchAll(),
        );
    }


    public function getById(int $id): ?ActiveRow
    {
        return $this->db->table('favorite')->get($id);
    }


    public function getByUserAndProceeding(int $userId, int $proceedingId): ?ActiveRow
    {
        return $this->db->table('favorite')
            ->where('user_id', $userId)
            ->where('proceeding_id', $proceedingId)
            ->fetch() ?: null;
    }


    /** Adds to the end of the ungrouped bucket. */
    public function add(int $userId, int $proceedingId, ?string $name): ActiveRow
    {
        return $this->transaction(function () use ($userId, $proceedingId, $name): ActiveRow {
            $row = $this->db->table('favorite')->insert([
                'user_id' => $userId,
                'proceeding_id' => $proceedingId,
                'name' => $name,
                'position' => $this->nextPosition($userId, null),
            ]);
            assert($row instanceof ActiveRow);
            return $row;
        });
    }


    public function update(int $id, array $data): void
    {
        $this->db->table('favorite')->wherePrimary($id)->update($data);
    }


    public function delete(ActiveRow $favorite): void
    {
        $this->transaction(function () use ($favorite): void {
            $userId = (int) $favorite->user_id;
            $groupId = $favorite->group_id === null ? null : (int) $favorite->group_id;
            $this->db->table('favorite')->wherePrimary((int) $favorite->id)->delete();
            $this->renumberBucket($userId, $groupId);
        });
    }


    /** Swaps the row with its bucket neighbor; no-op at the bucket edge. */
    public function move(ActiveRow $favorite, int $direction): void
    {
        $this->transaction(function () use ($favorite, $direction): void {
            $neighbor = $this->bucket((int) $favorite->user_id, $favorite->group_id === null ? null : (int) $favorite->group_id)
                ->where('position ' . ($direction < 0 ? '<' : '>') . ' ?', (int) $favorite->position)
                ->order('position ' . ($direction < 0 ? 'DESC' : 'ASC'))
                ->limit(1)
                ->fetch();
            if (!$neighbor instanceof ActiveRow) {
                return;
            }
            $position = (int) $favorite->position;
            $this->update((int) $favorite->id, ['position' => (int) $neighbor->position]);
            $this->update((int) $neighbor->id, ['position' => $position]);
        });
    }


    /** Moves the row to the end of the target bucket and compacts the old one. */
    public function moveToGroup(ActiveRow $favorite, ?int $groupId): void
    {
        $sourceGroupId = $favorite->group_id === null ? null : (int) $favorite->group_id;
        if ($sourceGroupId === $groupId) {
            return;
        }
        $this->transaction(function () use ($favorite, $groupId, $sourceGroupId): void {
            $this->update((int) $favorite->id, [
                'group_id' => $groupId,
                'position' => $this->nextPosition((int) $favorite->user_id, $groupId),
            ]);
            $this->renumberBucket((int) $favorite->user_id, $sourceGroupId);
        });
    }


    /** Appends the whole group bucket to the ungrouped one (order preserved). */
    public function ungroupAll(int $userId, int $groupId): void
    {
        $this->transaction(function () use ($userId, $groupId): void {
            $position = $this->nextPosition($userId, null);
            foreach ($this->bucket($userId, $groupId)->order('position') as $row) {
                $this->update((int) $row->id, ['group_id' => null, 'position' => $position++]);
            }
        });
    }


    private function renumberBucket(int $userId, ?int $groupId): void
    {
        $position = 1;
        foreach ($this->bucket($userId, $groupId)->order('position') as $row) {
            if ((int) $row->position !== $position) {
                $this->update((int) $row->id, ['position' => $position]);
            }
            $position++;
        }
    }


    private function nextPosition(int $userId, ?int $groupId): int
    {
        $max = $this->bucket($userId, $groupId)->max('position');
        return (int) $max + 1;
    }


    private function bucket(int $userId, ?int $groupId): Selection
    {
        return $this->db->table('favorite')
            ->where('user_id', $userId)
            ->where('group_id', $groupId);
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
