<?php declare(strict_types=1);

namespace App\Model\Hearing;


/**
 * Classifies a hearing-room label into (kind, off_site). A heuristic over the
 * free-text codelist labels; hand-curation happens via hearing_room.note.
 *
 * Order matters: a prison hearing room is still a prison ("jednací síň ve
 * Věznici ..."), so the off-site patterns are tested before the
 * plain-courtroom fallback. Off-site weakens the venue-court guess - the
 * hearing may belong to another court's case (see docs/infojednani-api.md).
 */
final class RoomClassifier
{
    /** @return array{HearingRoomKind, bool} [kind, off_site] */
    public static function classify(string $label): array
    {
        $l = mb_strtolower($label);
        return match (true) {
            // "vazební místnost" is a room inside the courthouse - only an
            // actual prison ("věznice") counts as off site.
            (bool) preg_match('~věznic~u', $l) => [HearingRoomKind::Prison, true],
            (bool) preg_match('~nemocnic|psychiatr|léčebn~u', $l) => [HearingRoomKind::Hospital, true],
            (bool) preg_match('~míst[oě] samé|na místě|místní ohledán|šetření na míst~u', $l) => [HearingRoomKind::Onsite, true],
            (bool) preg_match('~mimo budov|mimo soud|výslech mimo|vyklizení~u', $l) => [HearingRoomKind::External, true],
            (bool) preg_match('~videokonf~u', $l) => [HearingRoomKind::Video, false],
            (bool) preg_match('~kancelář~u', $l) => [HearingRoomKind::Office, false],
            default => [HearingRoomKind::Courtroom, false],
        };
    }
}
