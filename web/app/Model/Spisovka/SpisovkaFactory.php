<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\Codelist\RegistryRepository;
use Nette\Database\Table\ActiveRow;


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


    /** From a cached proceeding row (its identity columns). */
    public function fromProceeding(ActiveRow $row): Spisovka
    {
        return $this->fromCase(
            (int) $row->senate,
            (string) $row->registry_norm,
            (int) $row->bc_number,
            (int) $row->year,
        );
    }
}
