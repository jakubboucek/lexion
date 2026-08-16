<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\Codelist\RegistryRepository;


/**
 * Strict parser of our URL slug form "24-panc-141-2024" - the reverse pair to
 * Spisovka::toSlug(). Unlike SpisovkaParser it does not sanitize human input:
 * the slug must be exactly four dash-separated segments senate-registry-number-year.
 * The registry segment is lossy (slugified), so it is translated back to the
 * canonical display form via the codelist.
 */
final readonly class SpisovkaSlugParser
{
    public function __construct(
        private RegistryRepository $registries,
    ) {
    }


    /** @throws SpisovkaParseException */
    public function parse(string $slug): Spisovka
    {
        $parts = explode('-', $slug);
        if (count($parts) !== 4) {
            throw new SpisovkaParseException('Neplatný tvar spisové značky v adrese.');
        }
        [$senate, $registrySlug, $number, $year] = $parts;

        // The registry segment is matched case-insensitively so a wrong-case URL
        // still parses and gets canonicalized (redirected) rather than 404'd.
        if (
            !ctype_digit($senate)
            || !ctype_digit($number)
            || !ctype_digit($year)
            || strlen($year) !== 4
            || !preg_match('~^[a-zA-Z0-9]+$~', $registrySlug)
        ) {
            throw new SpisovkaParseException('Neplatný tvar spisové značky v adrese.');
        }

        // Translate the lossy registry slug back to its canonical display form;
        // fall back to uppercase for registries missing from the codelist.
        $registrySlug = strtolower($registrySlug);
        $registry = $this->registries->displayFromSlug($registrySlug) ?? mb_strtoupper($registrySlug);

        return new Spisovka(
            senate: (int) $senate,
            registry: $registry,
            number: (int) $number,
            year: (int) $year,
        );
    }
}
