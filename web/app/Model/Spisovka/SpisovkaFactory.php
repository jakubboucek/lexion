<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\CaseFile\CaseFile;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\Codelist\RegistryRepository;


/**
 * Builds a canonical (display-bearing) Spisovka from authoritative sources.
 * The codelist dependency lives here, not in the value object: it translates
 * the stored norm form ("P A NC") to the display form ("P a Nc"). Registries
 * missing from the codelist fall back to the norm form.
 */
final readonly class SpisovkaFactory
{
    public function __construct(
        private RegistryRepository $registries,
    ) {
    }


    public function fromCase(int $senate, string $registryNorm, int $number, int $year): Spisovka
    {
        $display = $this->registries->displayFromNorm($registryNorm) ?? $registryNorm;
        return new Spisovka($senate, $display, $number, $year);
    }


    /** From a stored case file row (its identity columns). */
    public function fromCaseFile(CaseFile $case): Spisovka
    {
        return $this->fromCase(
            $case->senate,
            $case->registryNorm,
            $case->bcNumber,
            $case->year,
        );
    }


    /** From the foreign-owner ref_* columns of a proceeding_event row. */
    public function fromEventRef(CaseFileEvent $event): Spisovka
    {
        return $this->fromCase(
            (int) $event->refSenate,
            (string) $event->refRegistryNorm,
            (int) $event->refBcNumber,
            (int) $event->refYear,
        );
    }
}
