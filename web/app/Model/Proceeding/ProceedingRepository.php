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


    /**
     * All cached cases with the given file number regardless of the court -
     * used to resolve court-less references (PRED_VEC) against the cache.
     *
     * @return list<ActiveRow>
     */
    public function findBySpisovka(string $registryNorm, int $senate, int $bcNumber, int $year): array
    {
        return array_values(
            $this->explorer->table('proceeding')
                ->where('registry_norm', strtoupper($registryNorm))
                ->where('senate', $senate)
                ->where('bc_number', $bcNumber)
                ->where('year', $year)
                ->fetchAll(),
        );
    }


    public function countAll(): int
    {
        return $this->explorer->table('proceeding')->count('*');
    }


    /** Case counts per court, highest first. @return array<string, int> */
    public function countPerCourt(): array
    {
        $counts = $this->explorer->table('proceeding')
            ->select('court_kod, COUNT(*) AS cnt')
            ->group('court_kod')
            ->order('cnt DESC')
            ->fetchPairs('court_kod', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Case counts per registry (normalized code), highest first. @return array<string, int> */
    public function countPerRegistry(): array
    {
        $counts = $this->explorer->table('proceeding')
            ->select('registry_norm, COUNT(*) AS cnt')
            ->group('registry_norm')
            ->order('cnt DESC')
            ->fetchPairs('registry_norm', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Case counts per file-number year, newest first. @return array<int, int> */
    public function countPerYear(): array
    {
        $counts = $this->explorer->table('proceeding')
            ->select('year, COUNT(*) AS cnt')
            ->group('year')
            ->order('year DESC')
            ->fetchPairs('year', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Cases holding data from the given source (infosoud/isir JSON column). */
    public function countWithSource(string $source): int
    {
        return $this->explorer->table('proceeding')
            ->where($source . '_json IS NOT NULL')
            ->count('*');
    }


    /** Most recent fetch time of the given source (infosoud/isir), if any. */
    public function lastFetchedAt(string $source): ?\DateTimeInterface
    {
        $max = $this->explorer->table('proceeding')->max($source . '_at');
        return $max instanceof \DateTimeInterface ? $max : null;
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
