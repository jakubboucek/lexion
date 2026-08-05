<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Spisovka\Spisovka;
use JakubBoucek\Hydrator\EntitySet;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Case files on record (see migrations 2026-07-18-02/03). Identity is
 * (court, registry, senate, number, year); per-source payloads live in JSON
 * columns and stay raw - callers merge their content themselves, this
 * repository stays thin.
 *
 * The class name still says Proceeding (renamed with the rest of the domain in
 * one wave); what it returns is already CaseFile.
 */
final readonly class ProceedingRepository
{
    /** @var Hydrator<CaseFile> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(CaseFile::class);
    }


    /**
     * Cases holding raw data from the given source, oldest first - a batch
     * reprojection walks these, so the stream stays lazy.
     *
     * @return EntitySet<CaseFile>
     */
    public function streamWithSource(DataSource $source): EntitySet
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('proceeding')
                ->where('?name IS NOT NULL', $source->jsonColumn())
                ->order('id'),
        );
    }


    public function getByCase(string $courtKod, Spisovka $spisovka): ?CaseFile
    {
        $row = $this->db->table('proceeding')
            ->where('court_kod', $courtKod)
            ->where('registry_norm', $spisovka->registryNorm())
            ->where('senate', $spisovka->senate)
            ->where('bc_number', $spisovka->number)
            ->where('year', $spisovka->year)
            ->fetch();
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /**
     * All cases on record with the given file number regardless of the court -
     * used to resolve court-less references (PRED_VEC).
     *
     * @return list<CaseFile>
     */
    public function findBySpisovka(Spisovka $spisovka): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('proceeding')
                ->where('registry_norm', $spisovka->registryNorm())
                ->where('senate', $spisovka->senate)
                ->where('bc_number', $spisovka->number)
                ->where('year', $spisovka->year),
        )->collectList();
    }


    /**
     * Cases by id, keyed by id - one query for a whole batch. Favorites hold
     * case ids and used to reach the rows through ActiveRow::ref(); a typed
     * entity has no such traversal, so the caller resolves the batch here
     * instead of querying row by row.
     *
     * @param list<int> $ids
     * @return array<int, CaseFile>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var array<int, CaseFile> keyed by the int property `id` */
        $cases = $this->hydrator
            ->fromDataSet($this->db->table('proceeding')->where('id', $ids), keyBy: 'id')
            ->collectMap();
        return $cases;
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
        $max = $this->db->table('proceeding')->max($source->atColumn());
        return $max instanceof \DateTimeInterface ? $max : null;
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(CaseFile $case): CaseFile
    {
        $row = $this->db->table('proceeding')->insert($this->hydrator->toData($case));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /** Patches the row with the initialized properties of $changes. */
    public function update(int $id, CaseFile $changes): void
    {
        $this->db->table('proceeding')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }
}
