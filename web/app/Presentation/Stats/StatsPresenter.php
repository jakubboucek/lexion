<?php declare(strict_types=1);

namespace App\Presentation\Stats;

use App\Model\CaseFile\CaseFileRepository;
use App\Model\CaseFile\DataSource;
use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\RegistryRepository;
use Nette;


/**
 * Public statistics of the case files loaded in the system: totals and
 * breakdowns per court, registry and file-number year.
 */
final class StatsPresenter extends Nette\Application\UI\Presenter
{
    public function __construct(
        private readonly CaseFileRepository $caseFiles,
        private readonly CourtRepository $courts,
        private readonly RegistryRepository $registries,
    ) {
        parent::__construct();
    }


    public function renderDefault(): void
    {
        $courtNames = [];
        foreach ($this->courts->findAll() as $court) {
            $courtNames[$court->kod] = $court->name;
        }

        $perCourt = [];
        foreach ($this->caseFiles->countPerCourt() as $kod => $count) {
            $perCourt[] = ['name' => $courtNames[$kod] ?? (string) $kod, 'count' => $count];
        }

        $perRegistry = [];
        foreach ($this->caseFiles->countPerRegistry() as $norm => $count) {
            $registries = $this->registries->findByNorm((string) $norm);
            $perRegistry[] = [
                'display' => $registries !== [] ? $registries[0]->code : (string) $norm,
                'description' => $registries[0]->description ?? null,
                'count' => $count,
            ];
        }

        $perYear = [];
        foreach ($this->caseFiles->countPerYear() as $year => $count) {
            $perYear[] = ['year' => $year, 'count' => $count];
        }

        $this->template->total = $this->caseFiles->countAll();
        $this->template->courtCount = count($perCourt);
        $this->template->withInfosoud = $this->caseFiles->countWithSource(DataSource::Infosoud);
        $this->template->withIsir = $this->caseFiles->countWithSource(DataSource::Isir);
        $this->template->lastInfosoudAt = $this->caseFiles->lastFetchedAt(DataSource::Infosoud);
        $this->template->lastIsirAt = $this->caseFiles->lastFetchedAt(DataSource::Isir);
        $this->template->perCourt = $perCourt;
        $this->template->perRegistry = $perRegistry;
        $this->template->perYear = $perYear;
    }
}
