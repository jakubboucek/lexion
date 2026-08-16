<?php declare(strict_types=1);

namespace App\Model\Favorite;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
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
    /** @var Hydrator<Favorite> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(Favorite::class);
    }


    /**
     * All favorites of the user, bucket by bucket in manual order.
     *
     * @return list<Favorite>
     */
    public function findByUser(int $userId): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('favorite')
                ->where('user_id', $userId)
                ->order('group_id, position'),
        )->collectList();
    }


    public function getById(int $id): ?Favorite
    {
        return $this->hydrate($this->db->table('favorite')->get($id));
    }


    public function getByUserAndProceeding(int $userId, int $proceedingId): ?Favorite
    {
        return $this->hydrate(
            $this->db->table('favorite')
                ->where('user_id', $userId)
                ->where('proceeding_id', $proceedingId)
                ->fetch(),
        );
    }


    /**
     * Adds to the end of a bucket: the group the caller picked, or the
     * ungrouped one when it left `groupId` alone. Which bucket the row belongs
     * to is the caller's business; where in the bucket it lands is not - the
     * 1..n ordering is this repository's invariant, so `position` is always
     * assigned here.
     */
    public function add(Favorite $favorite): Favorite
    {
        return $this->transaction(function () use ($favorite): Favorite {
            // groupId is nullable and null is a meaningful value here ("the
            // ungrouped bucket"), so isset() cannot tell it from "not filled".
            if (!$this->hydrator->isInitialized($favorite, 'groupId')) {
                $favorite->groupId = null;
            }
            $favorite->position = $this->nextPosition($favorite->userId, $favorite->groupId);
            $row = $this->db->table('favorite')->insert($this->hydrator->toData($favorite));
            assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
            return $this->hydrator->fromData($row);
        });
    }


    /** Patches the row with the initialized properties of $changes. */
    public function update(int $id, Favorite $changes): void
    {
        $this->db->table('favorite')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }


    public function delete(Favorite $favorite): void
    {
        $this->transaction(function () use ($favorite): void {
            $this->db->table('favorite')->wherePrimary($favorite->id)->delete();
            $this->renumberBucket($favorite->userId, $favorite->groupId);
        });
    }


    /** Swaps the row with its bucket neighbor; no-op at the bucket edge. */
    public function move(Favorite $favorite, int $direction): void
    {
        $this->transaction(function () use ($favorite, $direction): void {
            $neighbor = $this->hydrate(
                $this->bucket($favorite->userId, $favorite->groupId)
                    ->where('position ' . ($direction < 0 ? '<' : '>') . ' ?', $favorite->position)
                    ->order('position ' . ($direction < 0 ? 'DESC' : 'ASC'))
                    ->limit(1)
                    ->fetch(),
            );
            if ($neighbor === null) {
                return;
            }
            $this->reposition($favorite->id, $neighbor->position);
            $this->reposition($neighbor->id, $favorite->position);
        });
    }


    /** Moves the row to the end of the target bucket and compacts the old one. */
    public function moveToGroup(Favorite $favorite, ?int $groupId): void
    {
        $sourceGroupId = $favorite->groupId;
        if ($sourceGroupId === $groupId) {
            return;
        }
        $this->transaction(function () use ($favorite, $groupId, $sourceGroupId): void {
            $changes = new Favorite;
            $changes->groupId = $groupId;
            $changes->position = $this->nextPosition($favorite->userId, $groupId);
            $this->update($favorite->id, $changes);
            $this->renumberBucket($favorite->userId, $sourceGroupId);
        });
    }


    /** Appends the whole group bucket to the ungrouped one (order preserved). */
    public function ungroupAll(int $userId, int $groupId): void
    {
        $this->transaction(function () use ($userId, $groupId): void {
            $position = $this->nextPosition($userId, null);
            foreach ($this->bucketInOrder($userId, $groupId) as $favorite) {
                $changes = new Favorite;
                $changes->groupId = null;
                $changes->position = $position++;
                $this->update($favorite->id, $changes);
            }
        });
    }


    private function renumberBucket(int $userId, ?int $groupId): void
    {
        $position = 1;
        foreach ($this->bucketInOrder($userId, $groupId) as $favorite) {
            if ($favorite->position !== $position) {
                $this->reposition($favorite->id, $position);
            }
            $position++;
        }
    }


    private function reposition(int $id, int $position): void
    {
        $changes = new Favorite;
        $changes->position = $position;
        $this->update($id, $changes);
    }


    private function nextPosition(int $userId, ?int $groupId): int
    {
        $max = $this->bucket($userId, $groupId)->max('position');
        return (int) $max + 1;
    }


    /** @return list<Favorite> */
    private function bucketInOrder(int $userId, ?int $groupId): array
    {
        return $this->hydrator
            ->fromDataSet($this->bucket($userId, $groupId)->order('position'))
            ->collectList();
    }


    private function bucket(int $userId, ?int $groupId): Selection
    {
        return $this->db->table('favorite')
            ->where('user_id', $userId)
            ->where('group_id', $groupId);
    }


    private function hydrate(mixed $row): ?Favorite
    {
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
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
