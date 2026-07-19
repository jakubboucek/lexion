<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use Nette\Utils\Strings;


/**
 * One court file number as a value object. The registry is held in its display
 * form ("P a Nc" - the real spisová značka) when built from an authoritative
 * source (codelist/DB); the two derived forms are computed deterministically:
 *
 *   - display:  "24 P a Nc 141/2024"  -> format()       (user-facing)
 *   - API/norm: "P A NC"              -> registryNorm()  (infosoud API/URL)
 *   - slug:     "24-p-a-nc..." → "panc" -> toSlug()      (our URL)
 *
 * registryNorm() is invariant to casing (uppercase), toSlug() to casing AND
 * spacing/diacritics; both therefore match the codelist canonical form for any
 * reasonably-typed registry. Only format() depends on the actual stored form,
 * which is why format() is meaningful only on a codelist-canonical Spisovka.
 */
final readonly class Spisovka
{
    public function __construct(
        public int $senate,
        public string $registry,          // display form, e.g. "P a Nc"
        public int $number,
        public int $year,
        public ?string $courtPrefix = null, // uppercase ISIR court prefix (e.g. "KSPH"), human input only
        public ?int $attachedNumber = null, // trailing "-15" page number from a pasted č. j.
        public ?string $ignoredText = null, // trailing junk the parser dropped, human input only
    ) {
    }


    /** Uppercase API/normalized form used by infosoud (e.g. "P A NC"). */
    public function registryNorm(): string
    {
        return mb_strtoupper($this->registry);
    }


    /** Human display form, without court prefix and č. j. page number. */
    public function format(): string
    {
        return sprintf('%d %s %d/%d', $this->senate, $this->registry, $this->number, $this->year);
    }


    /** URL slug, e.g. "24-panc-141-2024". Deterministic reverse pair to SpisovkaSlugParser. */
    public function toSlug(): string
    {
        return sprintf('%d-%s-%d-%d', $this->senate, self::slugifyRegistry($this->registry), $this->number, $this->year);
    }


    /**
     * Slug form of a registry code: "P a Nc" -> "panc", "NSČR" -> "nscr".
     * Deterministic but lossy (diacritics and spaces dropped); the codelist
     * carries the canonical mapping back (see registry.slug + its test).
     */
    public static function slugifyRegistry(string $registry): string
    {
        return (string) preg_replace('~[^a-z0-9]~', '', strtolower(Strings::toAscii($registry)));
    }
}
