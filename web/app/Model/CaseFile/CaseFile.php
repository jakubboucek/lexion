<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Spisovka\Spisovka;
use JakubBoucek\Hydrator\Entity;


/**
 * A court case file we keep on record (table `case_file`, see migrations
 * 2026-07-18-02/03). Identity is the five-tuple (court, registry, senate,
 * number, year) - a file number is not unique without the court and the
 * senate.
 *
 * This is the "spisovna": the data is a building block of the app's key
 * features, not a disposable cache (see CLAUDE.md, *Terminologie*). The
 * entity already carries the target name of the domain; the table keeps the
 * old one until the coordinated DB rename.
 *
 * Per-source payloads stay **raw JSON strings** - they are verbatim snapshots
 * of what the source said, and their structure is read through the projection
 * tables (case_file_event / case_file_relation), never by typing them here.
 */
class CaseFile implements Entity
{
    public int $id;
    public string $courtKod;
    public string $registryNorm;
    public int $senate;
    public int $bcNumber;
    public int $year;
    public ?string $infosoudJson;
    public ?\DateTimeImmutable $infosoudAt;
    public ?string $isirJson;
    public ?\DateTimeImmutable $isirAt;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;


    /**
     * Identity of the file as a scalar key, for keying batches of references
     * (a page full of case chips asks about many files in one query).
     */
    public function key(): string
    {
        return $this->courtKod . '|' . $this->registryNorm . '|'
            . $this->senate . '|' . $this->bcNumber . '|' . $this->year;
    }


    /** The same key for a file we do not hold an entity of (yet). */
    public static function keyOf(string $courtKod, Spisovka $spisovka): string
    {
        return $courtKod . '|' . $spisovka->registryNorm() . '|'
            . $spisovka->senate . '|' . $spisovka->number . '|' . $spisovka->year;
    }


    /** Raw payload of the given source, if we hold one. */
    public function jsonOf(DataSource $source): ?string
    {
        return match ($source) {
            DataSource::Infosoud => $this->infosoudJson,
            DataSource::Isir => $this->isirJson,
        };
    }


    /** When the given source was last fetched into this file. */
    public function fetchedAt(DataSource $source): ?\DateTimeImmutable
    {
        return match ($source) {
            DataSource::Infosoud => $this->infosoudAt,
            DataSource::Isir => $this->isirAt,
        };
    }
}
