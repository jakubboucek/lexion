<?php declare(strict_types=1);

namespace App\Model\Codelist;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Court prefixes as used in ISIR-style file numbers ("KSPH 60 INS ...") mapped
 * to infosoud court codes.
 */
final readonly class CourtPrefixRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    public function getByPrefix(string $prefix): ?ActiveRow
    {
        return $this->explorer->table('court_prefix')->get(strtoupper($prefix));
    }
}
