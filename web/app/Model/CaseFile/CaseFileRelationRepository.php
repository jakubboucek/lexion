<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


/**
 * Directed N:M relations between case files. Endpoints are case identity
 * tuples, not FKs - the other side may not be loaded (or may not even be a
 * court case, e.g. a prosecutor file from PRED_VEC). Infosoud-sourced rows are
 * rebuilt by CaseFileProjectionService; manual rows always survive.
 */
final readonly class CaseFileRelationRepository
{
    /** @var Hydrator<CaseFileRelation> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(CaseFileRelation::class);
    }


    /**
     * Relations where the given case is the source side.
     *
     * $senate null matches any senate: references to Supreme Court cases carry
     * senate 0 instead of the real one (the same upstream quirk the event
     * projection tolerates), so a NS case would not find them otherwise.
     *
     * @return list<CaseFileRelation>
     */
    public function findBySrc(string $courtKod, string $registryNorm, ?int $senate, int $bcNumber, int $year): array
    {
        $selection = $this->db->table('proceeding_relation')
            ->where('src_court_kod', $courtKod)
            ->where('src_registry_norm', mb_strtoupper($registryNorm));
        if ($senate !== null) {
            $selection->where('src_senate', $senate);
        }
        return $this->collect(
            $selection
                ->where('src_bc_number', $bcNumber)
                ->where('src_year', $year),
        );
    }


    /**
     * Relations where the given case is the target side. $senate null matches
     * any senate - see findBySrc().
     *
     * @return list<CaseFileRelation>
     */
    public function findByDst(string $courtKod, string $registryNorm, ?int $senate, int $bcNumber, int $year): array
    {
        $selection = $this->db->table('proceeding_relation')
            ->where('dst_court_kod', $courtKod)
            ->where('dst_registry_norm', mb_strtoupper($registryNorm));
        if ($senate !== null) {
            $selection->where('dst_senate', $senate);
        }
        return $this->collect(
            $selection
                ->where('dst_bc_number', $bcNumber)
                ->where('dst_year', $year),
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
        $this->db->table('proceeding_relation')
            ->where('src_court_kod', $courtKod)
            ->where('src_registry_norm', mb_strtoupper($registryNorm))
            ->where('src_senate', $senate)
            ->where('src_bc_number', $bcNumber)
            ->where('src_year', $year)
            ->where('source', $source)
            ->delete();
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(CaseFileRelation $relation): CaseFileRelation
    {
        $row = $this->db->table('proceeding_relation')->insert($this->hydrator->toData($relation));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /** @return list<CaseFileRelation> */
    private function collect(Selection $selection): array
    {
        return $this->hydrator->fromDataSet($selection)->collectList();
    }
}
