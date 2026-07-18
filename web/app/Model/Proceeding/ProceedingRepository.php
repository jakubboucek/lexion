<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


/**
 * Soft cache of court proceedings (see migrations 2026-07-18-02/03). Identity
 * is (court, registry, senate, number, year); per-source payloads live in
 * JSON columns. Callers merge JSON content themselves - this repository
 * stays thin.
 */
final readonly class ProceedingRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    public function findAll(): Selection
    {
        return $this->explorer->table('proceeding');
    }


    public function getByCase(string $courtKod, string $registryNorm, int $senate, int $bcNumber, int $year): ?ActiveRow
    {
        return $this->explorer->table('proceeding')
            ->where('court_kod', $courtKod)
            ->where('registry_norm', strtoupper($registryNorm))
            ->where('senate', $senate)
            ->where('bc_number', $bcNumber)
            ->where('year', $year)
            ->fetch() ?: null;
    }


    public function insert(array $data): ActiveRow
    {
        $row = $this->explorer->table('proceeding')->insert($data);
        assert($row instanceof ActiveRow);
        return $row;
    }


    public function update(int $id, array $data): void
    {
        $this->explorer->table('proceeding')->wherePrimary($id)->update($data);
    }
}
