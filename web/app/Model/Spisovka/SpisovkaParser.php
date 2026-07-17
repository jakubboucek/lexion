<?php declare(strict_types=1);

namespace App\Model\Spisovka;


/**
 * Tolerant parser of Czech court file numbers pasted as free text.
 *
 * Accepted shapes (after normalization):
 *   - classic:       "24 NC 3601 / 2024", "12 C 34/2026"
 *   - ISIR-style:    "KSPH 60INS19742/2024" (court prefix, no spaces needed)
 *   - multi-word:    "0 P a Nc 205/2024"
 *   - č. j. extras:  leading "sp. zn."/"č. j." labels, trailing "-15" page number
 *
 * The parser is codelist-independent: it validates only the structure. Whether
 * the registry or court prefix exists is the resolver's job.
 */
final class SpisovkaParser
{
    public function parse(string $input): ParsedSpisovka
    {
        $text = trim($input);
        if ($text === '') {
            throw new SpisovkaParseException('Zadejte spisovou značku.');
        }

        // Leading labels: "sp. zn.", "sp.zn.", "spis. zn.", "č. j.", "čj.", optionally with a colon.
        $text = (string) preg_replace('~^\s*(sp(?:is)?\.?\s*zn(?:ačka)?\.?|č\.?\s*j\.?)\s*:?\s*~iu', '', $text);
        // Surrounding junk (quotes, brackets, stray punctuation).
        $text = trim($text, " \t\n\r\0\x0B\"'`„“”‚‘’()[]{}<>.,;:");

        // Tokenize into letter runs, digit runs and the two meaningful separators.
        preg_match_all('~\p{L}+|\d+|[/-]~u', $text, $matches);
        $tokens = $matches[0];
        if ($tokens === []) {
            throw new SpisovkaParseException('Toto nevypadá jako spisová značka.');
        }

        $pos = 0;
        $count = count($tokens);
        $isLetters = static fn(string $t): bool => (bool) preg_match('~^\p{L}+$~u', $t);
        $isDigits = static fn(string $t): bool => (bool) preg_match('~^\d+$~', $t);

        // Optional court prefix: a letter run followed by digits + letters (ISIR form).
        // A single leading letter run followed directly by digits+slash is a registry
        // without a senate number - reported below, not treated as a prefix.
        $courtPrefix = null;
        if (
            $isLetters($tokens[0])
            && isset($tokens[1], $tokens[2])
            && $isDigits($tokens[1])
            && $isLetters($tokens[2])
        ) {
            $courtPrefix = strtoupper($tokens[0]);
            $pos = 1;
        }

        // Senate number.
        if (!isset($tokens[$pos]) || !$isDigits($tokens[$pos])) {
            if (isset($tokens[$pos]) && $isLetters($tokens[$pos])) {
                throw new SpisovkaParseException('Chybí číslo senátu (spisová značka začíná číslem senátu, např. „12 C 34/2026").');
            }
            throw new SpisovkaParseException('Toto nevypadá jako spisová značka.');
        }
        $senate = (int) $tokens[$pos];
        $pos++;

        // Registry: one or more letter runs ("P a Nc" comes as three runs).
        $registryParts = [];
        while (isset($tokens[$pos]) && $isLetters($tokens[$pos])) {
            $registryParts[] = $tokens[$pos];
            $pos++;
        }
        if ($registryParts === []) {
            throw new SpisovkaParseException('Chybí označení rejstříku (např. „C", „T", „INS").');
        }
        $registry = implode(' ', $registryParts);

        // Case number.
        if (!isset($tokens[$pos]) || !$isDigits($tokens[$pos])) {
            throw new SpisovkaParseException('Chybí běžné číslo věci (číslo za rejstříkem).');
        }
        $number = (int) $tokens[$pos];
        $pos++;

        // Year: "/ 2024" (slash optional when the year is an unambiguous 4-digit run).
        if (isset($tokens[$pos]) && $tokens[$pos] === '/') {
            $pos++;
            if (!isset($tokens[$pos]) || !$isDigits($tokens[$pos])) {
                throw new SpisovkaParseException('Není uveden rok (ročník) spisové značky.');
            }
        } elseif (!isset($tokens[$pos]) || !$isDigits($tokens[$pos]) || strlen($tokens[$pos]) !== 4) {
            throw new SpisovkaParseException('Není uveden rok (ročník) spisové značky.');
        }
        if (strlen($tokens[$pos]) !== 4) {
            throw new SpisovkaParseException(sprintf('Rok „%s" nedává smysl – ročník má 4 číslice (např. 2024).', $tokens[$pos]));
        }
        $year = (int) $tokens[$pos];
        $pos++;

        // Optional č. j. page number: "-15" after the year.
        $attachedNumber = null;
        if (isset($tokens[$pos]) && $tokens[$pos] === '-' && isset($tokens[$pos + 1]) && $isDigits($tokens[$pos + 1])) {
            $attachedNumber = (int) $tokens[$pos + 1];
            $pos += 2;
        }

        if ($pos < $count) {
            throw new SpisovkaParseException(
                sprintf('Za spisovou značkou přebývá text „%s".', implode(' ', array_slice($tokens, $pos))),
            );
        }

        return new ParsedSpisovka($courtPrefix, $senate, $registry, $number, $year, $attachedNumber);
    }
}
