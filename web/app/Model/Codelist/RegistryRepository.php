<?php declare(strict_types=1);

namespace App\Model\Codelist;

use Nette\Database\Explorer;


/**
 * Codelist of court registries (druhy věcí). One registry code may exist at
 * multiple court levels (one row per level); court_level NULL = level unknown
 * (code seen only in the infosoud API lov).
 */
final readonly class RegistryRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    /** @return list<\Nette\Database\Table\ActiveRow> */
    public function findByNorm(string $codeNorm): array
    {
        return array_values(
            $this->explorer->table('registry')->where('code_norm', strtoupper($codeNorm))->fetchAll(),
        );
    }


    /** All distinct normalized codes – for typo suggestions. @return list<string> */
    public function findAllNorms(): array
    {
        return array_map(
            strval(...),
            $this->explorer->table('registry')->select('DISTINCT code_norm')->fetchPairs(null, 'code_norm'),
        );
    }
}
