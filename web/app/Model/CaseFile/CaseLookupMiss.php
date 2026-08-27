<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Entity;


/**
 * One documented deterministic miss of a case lookup (table `case_lookup_miss`,
 * migration 2026-08-28-00): an identity the upstream source did not answer -
 * not found, refused, or a year-mismatched response. The row is information for
 * a future fetcher, not a verdict; permanence is decided at read time
 * (CaseLookupMissRepository::isPermanent). A successful fetch of the identity
 * deletes the row.
 */
class CaseLookupMiss implements Entity
{
    public int $id;
    public string $courtKod;
    public string $registryNorm;
    public int $senate;
    public int $bcNumber;
    public int $year;
    public string $source;
    public CaseLookupOutcome $outcome;
    public int $attempts;
    public \DateTimeImmutable $firstAttemptAt;
    public \DateTimeImmutable $lastAttemptAt;
}
