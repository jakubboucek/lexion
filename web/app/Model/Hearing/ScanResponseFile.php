<?php declare(strict_types=1);

namespace App\Model\Hearing;

use Nette\Utils\Strings;


/**
 * Derives the on-disk file name of a stored infoJednani response from the
 * courtroom label itself. The label is the natural key - the API is queried
 * by the exact label and echoes it back in every response envelope
 * (jednaciSin) - so a name derived from it identifies the file regardless of
 * the _codelist.json snapshot. The previous positional name (the room's index
 * in the snapshot) broke as soon as the codelist drifted: an inserted room
 * shifted every index behind it (2026-08-26 incident, see
 * docs/infojednani-api.md).
 *
 * Format: <webalized 15-char label prefix>-<crc32 of the full label, 8 hex
 * digits>.json. The prefix is for humans; identity rests on the crc32 - real
 * labels share 15-char prefixes ("Lidická třída 20, II. patro č. dv. 315"
 * vs "... 407"). Webalize also lowercases, which keeps names collision-free
 * on case-insensitive filesystems (macOS) even for labels differing only in
 * case ("Místo samé" vs "místo samé") - those the crc32 tells apart.
 */
final class ScanResponseFile
{
    public static function nameFor(string $roomLabel): string
    {
        return sprintf(
            '%s-%08x.json',
            Strings::webalize(Strings::substring($roomLabel, 0, 15)),
            crc32($roomLabel),
        );
    }
}
