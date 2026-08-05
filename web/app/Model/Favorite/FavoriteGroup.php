<?php declare(strict_types=1);

namespace App\Model\Favorite;

use JakubBoucek\Hydrator\Entity;


/**
 * A user's group of favorites (see migration 2026-07-20-00). Names are unique
 * per user and `position` is the manual order of the group list, renumbered
 * 1..n after every mutation.
 */
class FavoriteGroup implements Entity
{
    public int $id;
    public int $userId;
    public string $name;
    public int $position;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
}
