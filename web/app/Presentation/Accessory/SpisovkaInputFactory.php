<?php declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Model\Codelist\CourtLevel;
use App\Model\Codelist\CourtRepository;
use Nette\Application\UI\Form;


/**
 * Reusable "spisovka" input pair: a free-text file number field + a court
 * select (grouped by court level). Used by the homepage tool now and by the
 * watch form later; live validation is handled by assets/spisovka-input.js
 * against the Spisovka:validate JSON endpoint.
 */
final readonly class SpisovkaInputFactory
{
    public function __construct(
        private CourtRepository $courts,
    ) {
    }


    public function addSpisovkaControls(Form $form): void
    {
        $form->addText('znacka', 'Spisová značka')
            ->setRequired('Zadejte spisovou značku.')
            ->setHtmlAttribute('placeholder', 'např. „12 C 34/2026“ nebo „KSPH 60 INS 19742/2024“')
            ->setHtmlAttribute('autocomplete', 'off');

        $form->addSelect('soud', 'Soud', $this->courtItems())
            ->setPrompt('(určit automaticky ze značky)')
            ->setHtmlAttribute('data-spisovka-court', '');
    }


    /**
     * Select items grouped into optgroups by court level, top courts first.
     *
     * @return array<string, array<string, string>>
     */
    private function courtItems(): array
    {
        $groups = [
            CourtLevel::Supreme->value => 'Nejvyšší soud',
            CourtLevel::SupremeAdministrative->value => 'Nejvyšší správní soud',
            CourtLevel::High->value => 'Vrchní soudy',
            CourtLevel::Regional->value => 'Krajské soudy',
            CourtLevel::District->value => 'Okresní soudy',
        ];
        $items = array_fill_keys(array_values($groups), []);
        foreach ($this->courts->findAll() as $court) {
            $items[$groups[$court->level->value]][$court->kod] = $court->name;
        }
        return array_filter($items);
    }
}
