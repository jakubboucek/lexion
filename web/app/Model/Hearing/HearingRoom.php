<?php declare(strict_types=1);

namespace App\Model\Hearing;

use JakubBoucek\Hydrator\Entity;


/**
 * One courtroom of the `hearing_room` codelist. The room has no upstream id -
 * the label itself is the identifier and it matches byte-for-byte across
 * infoJednani and infoSoud, so (court, label) is the strongest key available
 * (see migration 2026-07-26-01 and docs/infojednani-api.md).
 *
 * first_seen/last_seen/retired_at track presence in the upstream codelist;
 * a retired room must keep resolving for hearings already linked to it.
 */
class HearingRoom implements Entity
{
    public int $id;
    public string $courtKod;
    public string $label;
    public HearingRoomKind $kind;
    public bool $offSite;
    public ?string $note;
    public \DateTimeImmutable $firstSeen;
    public \DateTimeImmutable $lastSeen;
    public ?\DateTimeImmutable $retiredAt;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;


    /**
     * The (court, label) identity as one lookup key. Import runs resolve rooms
     * through a map instead of a query per room, and a not-yet-stored room
     * keys the same way as a stored one - so build the entity first and let it
     * produce its own key.
     */
    public function key(): string
    {
        return self::keyOf($this->courtKod, $this->label);
    }


    /** The same key built from loose values, for data that is not an entity yet. */
    public static function keyOf(string $courtKod, string $label): string
    {
        return $courtKod . '|' . $label;
    }
}
