<?php declare(strict_types=1);

namespace App\Model\Spisovka;


/**
 * Conversions of the year component of a file number.
 *
 * INTERNALLY the year is ALWAYS a full four-digit year (1961, 1999, 2024): in
 * Spisovka, in every table column, in our URLs. Only at the boundaries is it
 * converted, because the outside world uses the file number as the court wrote
 * it on the cover - and for pre-2000 cases that is a two-digit token ("0 P 480/61",
 * still live guardianship files from the 1960s onwards).
 *
 * There are deliberately TWO inbound conversions and they must never be swapped:
 *
 *   fromUserInput()  a human typed it, so "24" is shorthand for a recent year.
 *                    Pivot on the current year: two digits above it mean the
 *                    20th century ("98" -> 1998), at or below it the 21st
 *                    ("24" -> 2024).
 *
 *   fromUpstream()   infosoud/infoJednani echo the stored token, and they echo
 *                    modern cases in full ("rocnik": 2023). A two-digit value in
 *                    their data therefore ALWAYS means the 20th century - no
 *                    pivot, or a hypothetical 1905 case would become 2005.
 *
 * Outbound, forApi() strips a pre-2000 year back to two digits (what the public
 * SPA sends) and forDisplay() renders it the way the court writes it. Our own
 * URL slug keeps the full year and is strict about it - see SpisovkaSlugParser.
 *
 * See docs/infosoud-api.md for the upstream behaviour this mirrors.
 */
final class CaseYear
{
    /** Oldest plausible file number year; below this the input is a typo, not a case. */
    private const int MinYear = 1900;


    /**
     * Parses a year token typed by a human (two or four digits).
     *
     * @throws SpisovkaParseException
     */
    public static function fromUserInput(string $token, ?int $currentYear = null): int
    {
        $currentYear ??= (int) date('Y');

        if (strlen($token) === 2) {
            // Above the current year's last two digits the shorthand cannot mean
            // this century, so it is a 20th-century case ("98" -> 1998).
            $year = (int) $token > $currentYear % 100
                ? 1900 + (int) $token
                : 2000 + (int) $token;
        } elseif (strlen($token) === 4) {
            $year = (int) $token;
        } else {
            throw new SpisovkaParseException(
                sprintf('Rok „%s“ nedává smysl – zapište ročník celý (např. 2024), nebo zkráceně dvojčíslím („24“).', $token),
            );
        }

        // A file number cannot be issued in the future. This also guards the
        // upstream quirk where a year is matched on its last two digits, so
        // asking for 2098 would silently return the 1998 case.
        if ($year > $currentYear) {
            throw new SpisovkaParseException(
                sprintf('Ročník „%s“ by vyšel v budoucnosti (%d) – spisová značka z budoucnosti neexistuje.', $token, $year),
            );
        }
        if ($year < self::MinYear) {
            throw new SpisovkaParseException(
                sprintf('Ročník „%s“ nedává smysl – nejstarší evidované spisy jsou z 20. století.', $token),
            );
        }

        return $year;
    }


    /**
     * Normalizes the year as echoed by infosoud/infoJednani (`rocnik`), where a
     * two-digit value is always a 20th-century case.
     */
    public static function fromUpstream(int $rocnik): int
    {
        return $rocnik < 100 ? 1900 + $rocnik : $rocnik;
    }


    /** The year as the justice API expects it: two digits for pre-2000 cases. */
    public static function forApi(int $year): int
    {
        return $year < 2000 ? $year - 1900 : $year;
    }


    /** The year as the court writes it in the file number ("61", "2024"). */
    public static function forDisplay(int $year): string
    {
        // Zero-padded: a 1905 case is "05", never "5".
        return $year < 2000 ? sprintf('%02d', $year - 1900) : (string) $year;
    }
}
