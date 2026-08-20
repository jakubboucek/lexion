<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Codelist of case file relation types. Rows are directed: `label` describes
 * the target from the source's viewpoint, `label_reverse` the source from the
 * target's viewpoint. Backed by the cached snapshot (CodelistCache).
 */
final readonly class RelationTypeRepository
{
    public function __construct(
        private CodelistCache $codelists,
    ) {
    }


    /**
     * The whole codelist keyed by code - a handful of rows read as a lookup
     * table, never row by row.
     *
     * @return array<string, RelationTypeEntry>
     */
    public function findAll(): array
    {
        return $this->codelists->snapshot()->relationTypes->byCode;
    }
}
