<?php declare(strict_types=1);

namespace App\Presentation\Stats;

use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\RegistryRepository;
use App\Model\Proceeding\DataSource;
use App\Model\Proceeding\ProceedingRepository;
use Nette;


/**
 * Public statistics of the proceedings loaded in the system: totals and
 * breakdowns per court, registry and file-number year.
 */
final class StatsPresenter extends Nette\Application\UI\Presenter
{
    public function __construct(
        private readonly ProceedingRepository $proceedings,
        private readonly CourtRepository $courts,
        private readonly RegistryRepository $registries,
    ) {
        parent::__construct();
    }


    public function renderDefault(): void
    {
        $courtNames = [];
        foreach ($this->courts->findAll() as $court) {
            $courtNames[(string) $court->kod] = (string) $court->name;
        }

        $perCourt = [];
        foreach ($this->proceedings->countPerCourt() as $kod => $count) {
            $perCourt[] = ['name' => $courtNames[$kod] ?? (string) $kod, 'count' => $count];
        }

        $perRegistry = [];
        foreach ($this->proceedings->countPerRegistry() as $norm => $count) {
            $registries = $this->registries->findByNorm((string) $norm);
            $perRegistry[] = [
                'display' => $registries !== [] ? $registries[0]->code : (string) $norm,
                'description' => $registries[0]->description ?? null,
                'count' => $count,
            ];
        }

        $perYear = [];
        foreach ($this->proceedings->countPerYear() as $year => $count) {
            $perYear[] = ['year' => $year, 'count' => $count];
        }

        $this->template->total = $this->proceedings->countAll();
        $this->template->courtCount = count($perCourt);
        $this->template->withInfosoud = $this->proceedings->countWithSource(DataSource::Infosoud);
        $this->template->withIsir = $this->proceedings->countWithSource(DataSource::Isir);
        $this->template->lastInfosoudAt = $this->proceedings->lastFetchedAt(DataSource::Infosoud);
        $this->template->lastIsirAt = $this->proceedings->lastFetchedAt(DataSource::Isir);
        $this->template->perCourt = $perCourt;
        $this->template->perRegistry = $perRegistry;
        $this->template->perYear = $perYear;
    }
}
