<?php declare(strict_types=1);

namespace App\Model\Spisovka;


/**
 * A successfully parsed court file number (spisová značka). The registry is
 * kept as typed (spacing normalized); use registryNorm() for codelist lookups.
 */
final readonly class ParsedSpisovka
{
    public function __construct(
        public ?string $courtPrefix,   // uppercase court abbreviation from ISIR-style input (e.g. 'KSPH'), or null
        public int $senate,
        public string $registry,       // e.g. 'C', 'INS', 'P a Nc'
        public int $number,
        public int $year,
        public ?int $attachedNumber,   // trailing "-15" page number when a č. j. was pasted
    ) {
    }


    public function registryNorm(): string
    {
        return mb_strtoupper($this->registry);
    }


    /** Canonical display form, without the court prefix and č. j. page number. */
    public function format(): string
    {
        return sprintf('%d %s %d/%d', $this->senate, $this->registry, $this->number, $this->year);
    }
}
