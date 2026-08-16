<?php declare(strict_types=1);

namespace App\Model\Hearing;


/**
 * Textual pairing keys of one hearing, shared by the CLI import and binding
 * tools so the two can never drift apart. The room is deliberately NOT part
 * of any key (a hearing may appear in two rooms - the room is an attribute),
 * and the case-time key carries no court either: cross-court corroboration
 * against infoSoud matches on it (see bin/hearing-bind.php).
 */
final class HearingKey
{
    /** Case identity + date + minute, without the court. */
    public static function caseTime(
        string $registryNorm,
        int $senate,
        int $bcNumber,
        int $year,
        string $date,
        string $time,
    ): string
    {
        return implode('|', [$registryNorm, $senate, $bcNumber, $year, $date, $time]);
    }


    /** caseTime() prefixed with the venue court - the hearing unique identity. */
    public static function venueCaseTime(
        string $venueCourtKod,
        string $registryNorm,
        int $senate,
        int $bcNumber,
        int $year,
        string $date,
        string $time,
    ): string
    {
        return $venueCourtKod . '|' . self::caseTime($registryNorm, $senate, $bcNumber, $year, $date, $time);
    }
}
