<?php declare(strict_types=1);

namespace App\Model\Hearing;

use Nette;


/**
 * The merge semantics of a repeated sighting of one hearing, shared by every
 * writer of the `hearing` table (the scan importer, the sync) so the rules
 * cannot drift apart between them:
 *
 * - a sighting with a strictly fresher stamp refreshes the mutable attributes
 *   (type, judge, cancellation, non-public, result) and bumps last_seen_at;
 * - the primary room is only ever filled in, never replaced - a hearing
 *   occasionally shows up in two rooms, the first one stays primary and the
 *   others survive as observations; a missing room_id is back-filled when the
 *   label already matches.
 *
 * Pure entity-to-entity logic, no I/O: callers decide where the patch goes
 * (a repository update, a transaction, a dry run counter).
 */
final class HearingMergeRules
{
    use Nette\StaticClass;

    /**
     * The patch a new sighting applies to a stored hearing, or null when it
     * changes nothing. $incoming carries the sighting as a Hearing entity
     * with lastSeenAt = the sighting's own "valid as of" stamp.
     */
    public static function refreshPatch(Hearing $local, Hearing $incoming): ?Hearing
    {
        $patch = new Hearing;
        $changed = false;

        // Strictly fresher only - lastSeenAt is non-nullable on both sides,
        // so this is the plain form of the sync's Freshness rule.
        if ($incoming->lastSeenAt > $local->lastSeenAt) {
            $patch->hearingType = $incoming->hearingType;
            $patch->judge = $incoming->judge;
            $patch->cancelled = $incoming->cancelled;
            $patch->nonPublic = $incoming->nonPublic;
            $patch->result = $incoming->result;
            $patch->lastSeenAt = $incoming->lastSeenAt;
            $changed = true;
        }

        if ($local->room === null && $incoming->room !== null) {
            $patch->room = $incoming->room;
            $patch->roomId = $incoming->roomId;
            $changed = true;
        } elseif ($local->roomId === null && $incoming->roomId !== null && $local->room === $incoming->room) {
            $patch->roomId = $incoming->roomId;
            $changed = true;
        }

        return $changed ? $patch : null;
    }


    /**
     * Carries an applied patch back onto the in-memory entity, so a caller
     * holding a map of stored hearings (the scan importer walks tens of
     * thousands) keeps comparing later sightings against the updated state
     * without re-reading the row.
     */
    public static function applyToEntity(Hearing $local, Hearing $patch): void
    {
        if (isset($patch->lastSeenAt)) {
            $local->hearingType = $patch->hearingType;
            $local->judge = $patch->judge;
            $local->cancelled = $patch->cancelled;
            $local->nonPublic = $patch->nonPublic;
            $local->result = $patch->result;
            $local->lastSeenAt = $patch->lastSeenAt;
        }
        if (isset($patch->room)) {
            $local->room = $patch->room;
        }
        // roomId is nullable - a set null is indistinguishable from unset by
        // isset(), but the rules above only ever assign a non-null id.
        if (isset($patch->roomId)) {
            $local->roomId = $patch->roomId;
        }
    }
}
