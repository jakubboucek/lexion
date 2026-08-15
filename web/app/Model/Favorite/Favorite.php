<?php declare(strict_types=1);

namespace App\Model\Favorite;

use JakubBoucek\Hydrator\Entity;


/**
 * A case bookmarked by a user (see migration 2026-07-20-00). `name` is the
 * user's own label for the case, `groupId` NULL means the ungrouped section
 * and `position` is the manual order within that bucket - positions are
 * renumbered 1..n per bucket after every mutation, so they are only ever
 * meaningful together with (userId, groupId).
 */
class Favorite implements Entity
{
    /** Length of the `name` column - forms validate against it. */
    public const int NameMaxLength = 255;

    public int $id;
    public int $userId;
    public int $proceedingId;
    public ?int $groupId;
    public ?string $name;
    public int $position;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
}
