<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Codelist of court registries (druhy věcí). One registry code may exist at
 * multiple court levels (one row per level); court_level NULL = level unknown
 * (code seen only in the infosoud API lov). Three registry forms live here:
 * code (display "P a Nc"), code_norm (API "P A NC"), slug (URL "panc").
 * Backed by the cached snapshot (CodelistCache) - lookups are array accesses
 * on prebuilt maps, no SQL.
 */
final readonly class RegistryRepository
{
    public function __construct(
        private CodelistCache $codelists,
    ) {
    }


    /** All rows of the code - one per court level it is kept at. @return list<Registry> */
    public function findByNorm(string $codeNorm): array
    {
        return $this->codelists->snapshot()->registries->byNorm[strtoupper($codeNorm)] ?? [];
    }


    /** Display form (code) for a normalized code, e.g. "P A NC" -> "P a Nc". */
    public function displayFromNorm(string $codeNorm): ?string
    {
        return ($this->codelists->snapshot()->registries->byNorm[strtoupper($codeNorm)][0] ?? null)?->code;
    }


    /** Display form (code) for a URL slug, e.g. "panc" -> "P a Nc". Lossy reverse. */
    public function displayFromSlug(string $slug): ?string
    {
        return ($this->codelists->snapshot()->registries->bySlug[strtolower($slug)] ?? null)?->code;
    }


    /** All distinct normalized codes – for typo suggestions. @return list<string> */
    public function findAllNorms(): array
    {
        return $this->codelists->snapshot()->registries->allNorms;
    }
}
