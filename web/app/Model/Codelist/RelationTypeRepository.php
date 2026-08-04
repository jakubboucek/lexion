<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;


/**
 * Codelist of proceeding relation types. Rows are directed: `label` describes
 * the target from the source's viewpoint, `label_reverse` the source from the
 * target's viewpoint.
 */
final readonly class RelationTypeRepository
{
    /** @var Hydrator<RelationTypeEntry> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(RelationTypeEntry::class);
    }


    /**
     * The whole codelist keyed by code - a handful of rows read as a lookup
     * table, never row by row.
     *
     * @return array<string, RelationTypeEntry>
     */
    public function findAll(): array
    {
        /** @var array<string, RelationTypeEntry> re-keyed by the string property `code` */
        $types = $this->hydrator
            ->fromDataSet($this->db->table('relation_type'), keyBy: 'code')
            ->collectMap();
        return $types;
    }
}
