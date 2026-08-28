<?php declare(strict_types=1);

namespace App\Model\CaseFile;

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
 */
final readonly class CaseFileRepository
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
            $this->db->table('case_file')
                ->where('?name IS NOT NULL', $source->jsonColumn())
                ->order('id'),
        );
    }


    public function getByCase(string $courtKod, Spisovka $spisovka): ?CaseFile
    {
        return $this->getByIdentity(
            $courtKod,
            $spisovka->registryNorm(),
            $spisovka->senate,
            $spisovka->number,
            $spisovka->year,
        );
    }


    /**
     * The same lookup by the bare identity tuple - for callers that read the
     * five parts off the wire and have no Spisovka to build from them (the
     * sync import).
     */
    public function getByIdentity(
        string $courtKod,
        string $registryNorm,
        int $senate,
        int $bcNumber,
        int $year,
    ): ?CaseFile
    {
        $row = $this->db->table('case_file')
            ->where('court_kod', $courtKod)
            ->where('registry_norm', $registryNorm)
            ->where('senate', $senate)
            ->where('bc_number', $bcNumber)
            ->where('year', $year)
            ->fetch();
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /**
     * Cases by full identity, keyed by CaseFile::key() - one query for a whole
     * page worth of references. A case detail renders a dozen chips of other
     * cases and each of them used to ask "do we hold this one?" on its own.
     *
     * @param list<array{string, Spisovka}> $cases court kod + file number pairs
     * @return array<string, CaseFile> only the cases actually on record
     */
    public function findByCases(array $cases): array
    {
        $tuples = [];
        foreach ($cases as [$courtKod, $spisovka]) {
            $tuples[CaseFile::keyOf($courtKod, $spisovka)] = [
                $courtKod,
                $spisovka->registryNorm(),
                $spisovka->senate,
                $spisovka->number,
                $spisovka->year,
            ];
        }
        if ($tuples === []) {
            return [];
        }

        $found = [];
        $rows = $this->hydrator->fromDataSet(
            $this->db->table('case_file')
                ->where('(court_kod, registry_norm, senate, bc_number, year) IN', array_values($tuples)),
        );
        foreach ($rows as $case) {
            $found[$case->key()] = $case;
        }
        return $found;
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
            $this->db->table('case_file')
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
            ->fromDataSet($this->db->table('case_file')->where('id', $ids), keyBy: 'id')
            ->collectMap();
        return $cases;
    }


    /**
     * Ids of all case files in ascending order - the sync export slices them
     * into parts and streams each part by its id range, so it never pages
     * with OFFSET over a table that may change under it.
     *
     * @return list<int>
     */
    public function allIds(): array
    {
        $ids = $this->db->table('case_file')->order('id')->fetchPairs(null, 'id');
        return array_map(intval(...), array_values($ids));
    }


    public function countAll(): int
    {
        return $this->db->table('case_file')->count('*');
    }


    /** Case counts per court, highest first. @return array<string, int> */
    public function countPerCourt(): array
    {
        $counts = $this->db->table('case_file')
            ->select('court_kod, COUNT(*) AS cnt')
            ->group('court_kod')
            ->order('cnt DESC')
            ->fetchPairs('court_kod', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Case counts per registry (normalized code), highest first. @return array<string, int> */
    public function countPerRegistry(): array
    {
        $counts = $this->db->table('case_file')
            ->select('registry_norm, COUNT(*) AS cnt')
            ->group('registry_norm')
            ->order('cnt DESC')
            ->fetchPairs('registry_norm', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Case counts per file-number year, newest first. @return array<int, int> */
    public function countPerYear(): array
    {
        $counts = $this->db->table('case_file')
            ->select('year, COUNT(*) AS cnt')
            ->group('year')
            ->order('year DESC')
            ->fetchPairs('year', 'cnt');
        return array_map(intval(...), $counts);
    }


    /** Cases holding data from the given source. */
    public function countWithSource(DataSource $source): int
    {
        return $this->db->table('case_file')
            ->where('?name IS NOT NULL', $source->jsonColumn())
            ->count('*');
    }


    /** Most recent fetch time of the given source, if any. */
    public function lastFetchedAt(DataSource $source): ?\DateTimeInterface
    {
        $max = $this->db->table('case_file')->max($source->atColumn());
        return $max instanceof \DateTimeInterface ? $max : null;
    }


    /**
     * Case numbers on record in one number series, within [from, to] (to = null
     * means open-ended), ascending. The series scanner reads these to know what
     * it already holds. Only rows carrying infosoud data count as a hit - an
     * ISIR-only row never saw the overview endpoint.
     *
     * @return list<int>
     */
    public function numbersInSeries(
        string $courtKod,
        string $registryNorm,
        int $senate,
        int $year,
        int $from,
        ?int $to,
    ): array
    {
        $selection = $this->db->table('case_file')
            ->where('court_kod', $courtKod)
            ->where('registry_norm', $registryNorm)
            ->where('senate', $senate)
            ->where('year', $year)
            ->where('infosoud_at IS NOT NULL')
            ->where('bc_number >= ?', $from);
        if ($to !== null) {
            $selection->where('bc_number <= ?', $to);
        }
        $numbers = $selection->order('bc_number')->fetchPairs(null, 'bc_number');
        return array_map(intval(...), array_values($numbers));
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(CaseFile $case): CaseFile
    {
        $row = $this->db->table('case_file')->insert($this->hydrator->toData($case));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /** Patches the row with the initialized properties of $changes. */
    public function update(int $id, CaseFile $changes): void
    {
        $this->db->table('case_file')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }
}
