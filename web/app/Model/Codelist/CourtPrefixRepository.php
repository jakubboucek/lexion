<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Court prefixes as used in ISIR-style file numbers ("KSPH 60 INS ...") mapped
 * to infosoud court codes. Backed by the cached snapshot (CodelistCache).
 */
final readonly class CourtPrefixRepository
{
    public function __construct(
        private CodelistCache $codelists,
    ) {
    }


    public function getByPrefix(string $prefix): ?CourtPrefix
    {
        return $this->codelists->snapshot()->courtPrefixes->byPrefix[strtoupper($prefix)] ?? null;
    }
}
