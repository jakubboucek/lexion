<?php declare(strict_types=1);

namespace App\Presentation\Home;

use App\Model\Codelist\CourtLevel;
use App\Model\Codelist\CourtRepository;
use Nette;


/**
 * Homepage = the spisovka tool. In this variant the tool is a Vue island: the
 * presenter renders no form at all, only the data the island starts from
 * (court codelist + prefill). Everything the user does then goes through the
 * JSON endpoints of the Spisovka presenter - validate while typing, resolve on
 * submit - so the form has exactly one implementation instead of a server-
 * rendered one and a live one that drift apart.
 */
final class HomePresenter extends Nette\Application\UI\Presenter
{
    public function __construct(
        private readonly CourtRepository $courts,
    ) {
        parent::__construct();
    }


    /**
     * GET params prefill the tool: the back link from the case detail passes
     * the current case, and the island mirrors its values into the URL
     * (history.replaceState) right before it navigates away, so the browser
     * back button restores the search.
     */
    public function renderDefault(?string $znacka = null, ?string $soud = null): void
    {
        $this->template->state = [
            'validateUrl' => $this->link(':Spisovka:validate'),
            'resolveUrl' => $this->link(':Spisovka:resolve'),
            'znacka' => $znacka ?? '',
            'soud' => $soud !== null && $this->courts->getByKod($soud) !== null ? $soud : '',
            'courtGroups' => $this->courtGroups(),
        ];
    }


    /**
     * Courts for the island's combobox, grouped by court level (top first) -
     * the same shape the server-rendered optgroups had.
     *
     * @return list<array{label: string, courts: list<array{kod: string, name: string}>}>
     */
    private function courtGroups(): array
    {
        $labels = [
            CourtLevel::Supreme->value => 'Nejvyšší soud',
            CourtLevel::SupremeAdministrative->value => 'Nejvyšší správní soud',
            CourtLevel::High->value => 'Vrchní soudy',
            CourtLevel::Regional->value => 'Krajské soudy',
            CourtLevel::District->value => 'Okresní soudy',
        ];
        $groups = array_fill_keys(array_keys($labels), []);
        foreach ($this->courts->findAll() as $court) {
            $groups[$court->level->value][] = ['kod' => (string) $court->kod, 'name' => $court->name];
        }

        $result = [];
        foreach ($labels as $level => $label) {
            if ($groups[$level] !== []) {
                $result[] = ['label' => $label, 'courts' => $groups[$level]];
            }
        }
        return $result;
    }
}
