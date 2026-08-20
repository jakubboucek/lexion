<?php declare(strict_types=1);

namespace App\Model\Hearing;


/**
 * Kind of place a hearing is held at - the value set of `hearing_room.kind`
 * (migration 2026-07-26-01 enforces exactly these codes with a CHECK).
 * Assigned heuristically from the room label by RoomClassifier and curatable
 * by hand afterwards.
 *
 * Off-site kinds matter for the hearing -> case file binding: the venue court
 * is only a candidate home court and a hearing held outside the courthouse is
 * the weakest signal of all (see docs/infojednani-api.md). The off-site flag
 * stays a separate column though - it is curated independently of the kind.
 */
enum HearingRoomKind: string
{
    case Courtroom = 'courtroom';
    case Video = 'video';
    case Office = 'office';
    case Prison = 'prison';
    case Hospital = 'hospital';
    case Onsite = 'onsite';
    case External = 'external';
    case Unknown = 'unknown';
}
