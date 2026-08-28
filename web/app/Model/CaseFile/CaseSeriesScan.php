<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Entity;


/**
 * One scanned number-series block (table `case_series_scan`, migration
 * 2026-08-28-01). Identity is (court, registry, senate, year, numberFrom);
 * numberConfirmedEnd/confirmedAt stay NULL until the scanner confirms the end
 * by its rules. See docs/navrh-sken-rad.md.
 */
class CaseSeriesScan implements Entity
{
    public int $id;
    public string $courtKod;
    public string $registryNorm;
    public int $senate;
    public int $year;
    public int $numberFrom;
    /** NULL until the scan confirmed the series end (early stop / hard ceiling = stays NULL). */
    public ?int $numberConfirmedEnd;
    public ?\DateTimeImmutable $confirmedAt;
    public \DateTimeImmutable $scannedAt;
    public \DateTimeImmutable $createdAt;
}
