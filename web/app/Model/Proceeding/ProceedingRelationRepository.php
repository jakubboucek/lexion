<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Directed N:M relations between proceedings. Endpoints are case identity
 * tuples, not FKs - the other side may not be loaded (or may not even be a
 * court case, e.g. a prosecutor file from PRED_VEC). Infosoud-sourced rows are
 * rebuilt by ProceedingProjectionService; manual rows always survive.
 */
final readonly class ProceedingRelationRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    /** @return list<ActiveRow> relations where the given case is the source side */
    public function findBySrc(string $courtKod, string $registryNorm, int $senate, int $bcNumber, int $year): array
    {
        return array_values(
            $this->explorer->table('proceeding_relation')
                ->where('src_court_kod', $courtKod)
                ->where('src_registry_norm', strtoupper($registryNorm))
                ->where('src_senate', $senate)
                ->where('src_bc_number', $bcNumber)
                ->where('src_year', $year)
                ->fetchAll(),
        );
    }


    /** @return list<ActiveRow> relations where the given case is the target side */
    public function findByDst(string $courtKod, string $registryNorm, int $senate, int $bcNumber, int $year): array
    {
        return array_values(
            $this->explorer->table('proceeding_relation')
                ->where('dst_court_kod', $courtKod)
                ->where('dst_registry_norm', strtoupper($registryNorm))
                ->where('dst_senate', $senate)
                ->where('dst_bc_number', $bcNumber)
                ->where('dst_year', $year)
                ->fetchAll(),
        );
    }


    /** Removes all relations of one source case coming from the given data source. */
    public function deleteBySrcAndSource(
        string $courtKod,
        string $registryNorm,
        int $senate,
        int $bcNumber,
        int $year,
        string $source,
    ): void
    {
        $this->explorer->table('proceeding_relation')
            ->where('src_court_kod', $courtKod)
            ->where('src_registry_norm', strtoupper($registryNorm))
            ->where('src_senate', $senate)
            ->where('src_bc_number', $bcNumber)
            ->where('src_year', $year)
            ->where('source', $source)
            ->delete();
    }


    public function insert(array $data): ActiveRow
    {
        $row = $this->explorer->table('proceeding_relation')->insert($data);
        assert($row instanceof ActiveRow);
        return $row;
    }
}
