<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Serializable index of the court_prefix codelist inside CodelistSnapshot.
 * A dumb data holder: query logic stays in CourtPrefixRepository.
 */
final readonly class CourtPrefixSet
{
    /** @param array<string, CourtPrefix> $byPrefix keyed by prefix (uppercase) */
    public function __construct(
        public array $byPrefix,
    ) {
    }
}
