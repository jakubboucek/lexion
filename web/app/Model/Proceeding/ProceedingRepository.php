<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Spisovka\Spisovka;
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
        private Explorer $db,
    ) {
    }


    public function findAll(): Selection
    {
        return $this->db->table('proceeding');
    }


    public function getByCase(string $courtKod, Spisovka $spisovka): ?ActiveRow
    {
        return $this->db->table('proceeding')
            ->where('court_kod', $courtKod)
            ->where('registry_norm', $spisovka->registryNorm())
            ->where('senate', $spisovka->senate)
            ->where('bc_number', $spisovka->number)
            ->where('year', $spisovka->year)
            ->fetch() ?: null;
    }


    /**
     * All cached cases with the given file number regardless of the court -
     * used to resolve court-less references (PRED_VEC) against the cache.
     *
     * @return list<ActiveRow>
     */
    public function findBySpisovka(Spisovka $spisovka): array
    {
        return array_values(
            $this->db->table('proceeding')
                ->where('registry_norm', $spisovka->registryNorm())
                ->where('senate', $spisovka->senate)
                ->where('bc_number', $spisovka->number)
                ->where('year', $spisovka->year)
                ->fetchAll(),
        );
    }


    public function countAll(): int
    {
        return $this->db->table('proceeding')->count('*');
    }


    /** Case counts per court, highest first. @return array<string, int> */
    public function countPerCourt(): array
    {
        $counts = $this->db->table('proceeding')
            ->select('court_kod, COUNT(*) AS cnt')
            ->group('court_kod')
            ->order('cnt DESC')
            ->fetchPairs('court_kod', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Case counts per registry (normalized code), highest first. @return array<string, int> */
    public function countPerRegistry(): array
    {
        $counts = $this->db->table('proceeding')
            ->select('registry_norm, COUNT(*) AS cnt')
            ->group('registry_norm')
            ->order('cnt DESC')
            ->fetchPairs('registry_norm', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Case counts per file-number year, newest first. @return array<int, int> */
    public function countPerYear(): array
    {
        $counts = $this->db->table('proceeding')
            ->select('year, COUNT(*) AS cnt')
            ->group('year')
            ->order('year DESC')
            ->fetchPairs('year', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Cases holding data from the given source. */
    public function countWithSource(DataSource $source): int
    {
        return $this->db->table('proceeding')
            ->where('?name IS NOT NULL', $source->jsonColumn())
            ->count('*');
    }


    /** Most recent fetch time of the given source, if any. */
    public function lastFetchedAt(DataSource $source): ?\DateTimeInterface
    {
        $max = $this->db->table('proceeding')
            ->select('MAX(?name) AS m', $source->atColumn())
            ->fetch()?->m;
        return $max instanceof \DateTimeInterface ? $max : null;
    }


    public function insert(array $data): ActiveRow
    {
        $row = $this->db->table('proceeding')->insert($data);
        assert($row instanceof ActiveRow);
        return $row;
    }


    public function update(int $id, array $data): void
    {
        $this->db->table('proceeding')->wherePrimary($id)->update($data);
    }
}
