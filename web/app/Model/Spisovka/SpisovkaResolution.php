<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\Codelist\CourtLevel;


/**
 * Result of resolving a parsed file number against the codelists: validation
 * messages plus what we could infer about the court.
 */
final readonly class SpisovkaResolution
{
    /**
     * @param list<string> $errors user-facing problems (registry unknown, ...)
     * @param list<string> $warnings user-facing notes that do not block usage
     * @param list<string> $registrySuggestions display codes offered for a typo ("mysleli jste ...?")
     * @param list<CourtLevel> $registryLevels court levels where the registry exists (empty = unknown)
     * @param list<string> $candidateCourtKods courts matching the constraints; empty while $fixedCourtKod is null = no constraint
     */
    public function __construct(
        public Spisovka $spisovka,
        public array $errors,
        public array $warnings,
        public array $registrySuggestions,
        public array $registryLevels,
        public ?string $registryDescription,
        public ?string $fixedCourtKod,      // court determined unambiguously (prefix, senate rule, NS-only registry)
        public ?string $fixedCourtReason,   // short user-facing note why the court was fixed
        public array $candidateCourtKods,
    ) {
    }


    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
