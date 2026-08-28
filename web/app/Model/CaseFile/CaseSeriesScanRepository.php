<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Ledger of scanned number-series blocks (table `case_series_scan`). Written by
 * CaseSeriesScanService at the end of each block's scan; read to skip recently
 * scanned blocks and to see confirmed series ends. Thin.
 */
final readonly class CaseSeriesScanRepository
{
    /** @var Hydrator<CaseSeriesScan> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(CaseSeriesScan::class);
    }


    public function find(string $courtKod, string $registryNorm, int $senate, int $year, int $numberFrom): ?CaseSeriesScan
    {
        $row = $this->identity($courtKod, $registryNorm, $senate, $year, $numberFrom)->fetch();
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /**
     * Upserts the block's scan record. numberConfirmedEnd/confirmedAt are set
     * only when the caller passes a confirmed end; a NULL end leaves them NULL
     * (and clears a previously confirmed end - the series has changed).
     */
    public function record(
        string $courtKod,
        string $registryNorm,
        int $senate,
        int $year,
        int $numberFrom,
        ?int $confirmedEnd,
        \DateTimeImmutable $scannedAt,
    ): void
    {
        $confirmedAt = $confirmedEnd !== null ? $scannedAt : null;
        $updated = $this->identity($courtKod, $registryNorm, $senate, $year, $numberFrom)->update([
            'number_confirmed_end' => $confirmedEnd,
            'confirmed_at' => $confirmedAt,
            'scanned_at' => $scannedAt,
        ]);
        if ($updated > 0) {
            return;
        }
        $scan = new CaseSeriesScan;
        $scan->courtKod = $courtKod;
        $scan->registryNorm = $registryNorm;
        $scan->senate = $senate;
        $scan->year = $year;
        $scan->numberFrom = $numberFrom;
        $scan->numberConfirmedEnd = $confirmedEnd;
        $scan->confirmedAt = $confirmedAt;
        $scan->scannedAt = $scannedAt;
        $this->db->table('case_series_scan')->insert($this->hydrator->toData($scan));
    }


    /** @return \Nette\Database\Table\Selection */
    private function identity(string $courtKod, string $registryNorm, int $senate, int $year, int $numberFrom)
    {
        return $this->db->table('case_series_scan')
            ->where('court_kod', $courtKod)
            ->where('registry_norm', $registryNorm)
            ->where('senate', $senate)
            ->where('year', $year)
            ->where('number_from', $numberFrom);
    }
}
