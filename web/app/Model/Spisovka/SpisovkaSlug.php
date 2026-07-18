<?php declare(strict_types=1);

namespace App\Model\Spisovka;


/**
 * URL slug form of a file number: "24-NC-3601-2024" (multi-word registries
 * keep their words: "24-P-A-NC-141-2024"). Unambiguous because the senate is
 * always first, the year is the trailing 4-digit run and the registry is
 * whatever letters lie between the senate and the case number.
 */
final class SpisovkaSlug
{
    public static function format(ParsedSpisovka $spisovka): string
    {
        return sprintf(
            '%d-%s-%d-%d',
            $spisovka->senate,
            str_replace(' ', '-', $spisovka->registryNorm()),
            $spisovka->number,
            $spisovka->year,
        );
    }


    /** @throws SpisovkaParseException */
    public static function parse(string $slug, SpisovkaParser $parser): ParsedSpisovka
    {
        return $parser->parse(str_replace('-', ' ', $slug));
    }
}
