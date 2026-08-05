<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Serializable index of the registry codelist inside CodelistSnapshot. One
 * norm maps to several rows (one per court level), so the primary map groups
 * lists; the display form of a norm is the code of its first row. A dumb data
 * holder: query logic stays in RegistryRepository.
 */
final readonly class RegistrySet
{
    /**
     * @param array<string, list<Registry>> $byNorm rows per normalized code,
     *   in the DB order (code_norm, court_level)
     * @param array<string, Registry> $bySlug first row per slug (lowercase)
     * @param list<string> $allNorms distinct normalized codes
     */
    public function __construct(
        public array $byNorm,
        public array $bySlug,
        public array $allNorms,
    ) {
    }
}
