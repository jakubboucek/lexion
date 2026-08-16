<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * The single serialized root of the codelist cache: one mutually consistent
 * imprint of all cached codelist tables (a fresh court never mixes with a
 * stale registry). Built by CodelistCache, consumed by the codelist
 * repositories. See docs/analyza-ciselniky.md for the design.
 */
final readonly class CodelistSnapshot
{
    public function __construct(
        public CourtSet $courts,
        public RegistrySet $registries,
        public CourtPrefixSet $courtPrefixes,
        public RelationTypeSet $relationTypes,
        public \DateTimeImmutable $generatedAt,
    ) {
    }
}
