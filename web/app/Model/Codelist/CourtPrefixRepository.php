<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Court prefixes as used in ISIR-style file numbers ("KSPH 60 INS ...") mapped
 * to infosoud court codes.
 */
final readonly class CourtPrefixRepository
{
    /** @var Hydrator<CourtPrefix> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(CourtPrefix::class);
    }


    public function getByPrefix(string $prefix): ?CourtPrefix
    {
        $row = $this->db->table('court_prefix')->get(strtoupper($prefix));
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }
}
