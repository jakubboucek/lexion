<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Serializable index of the relation_type codelist inside CodelistSnapshot.
 * A dumb data holder: query logic stays in RelationTypeRepository.
 */
final readonly class RelationTypeSet
{
    /** @param array<string, RelationTypeEntry> $byCode keyed by code */
    public function __construct(
        public array $byCode,
    ) {
    }
}
