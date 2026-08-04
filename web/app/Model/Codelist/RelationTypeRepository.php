<?php declare(strict_types=1);

namespace App\Model\Codelist;

use Nette\Database\Explorer;


/**
 * Codelist of proceeding relation types. Rows are directed: `label` describes
 * the target from the source's viewpoint, `label_reverse` the source from the
 * target's viewpoint.
 */
final readonly class RelationTypeRepository
{
    public function __construct(
        private Explorer $db,
    ) {
    }


    /** @return array<string, array{label: string, labelReverse: string}> keyed by code */
    public function findAll(): array
    {
        $types = [];
        foreach ($this->db->table('relation_type') as $row) {
            $types[(string) $row->code] = [
                'label' => (string) $row->label,
                'labelReverse' => (string) $row->label_reverse,
            ];
        }
        return $types;
    }
}
