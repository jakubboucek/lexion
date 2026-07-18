<?php declare(strict_types=1);

namespace App\Presentation\Spisovka;

use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Spisovka\ParsedSpisovka;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use App\Model\Spisovka\SpisovkaResolver;
use App\Model\Spisovka\SpisovkaSlug;
use App\Presentation\Accessory\SpisovkaInputFactory;
use Nette;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;


/**
 * Public tool: paste a file number as free text, get validation and a direct
 * link to infosoud. Also exposes the JSON validation endpoint reused by the
 * spisovka input component elsewhere.
 */
final class SpisovkaPresenter extends Nette\Application\UI\Presenter
{
    public function __construct(
        private readonly SpisovkaParser $parser,
        private readonly SpisovkaResolver $resolver,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CourtRepository $courts,
        private readonly SpisovkaInputFactory $spisovkaInput,
    ) {
        parent::__construct();
    }


    /** Live-validation JSON endpoint for the spisovka input component. */
    #[Requires(methods: 'GET')]
    public function actionValidate(string $text = ''): never
    {
        try {
            $parsed = $this->parser->parse($text);
        } catch (SpisovkaParseException $e) {
            $this->sendJson(['ok' => false, 'error' => $e->getMessage()]);
        }

        $resolution = $this->resolver->resolve($parsed);

        $fixedCourt = null;
        $infosoudUrl = null;
        if ($resolution->fixedCourtKod !== null) {
            $court = $this->courts->getByKod($resolution->fixedCourtKod);
            if ($court !== null) {
                $fixedCourt = [
                    'kod' => (string) $court->kod,
                    'name' => (string) $court->name,
                    'reason' => $resolution->fixedCourtReason,
                ];
                $infosoudUrl = $this->linkBuilder->detailUrl($parsed, $court);
            }
        }

        $this->sendJson([
            'ok' => true,
            'normalized' => $parsed->format(),
            'prefix' => $parsed->courtPrefix,
            'errors' => $resolution->errors,
            'warnings' => $resolution->warnings,
            'suggestions' => array_map(
                fn(string $code) => ['code' => $code, 'text' => $this->buildSuggestionText($parsed, $code)],
                $resolution->registrySuggestions,
            ),
            'registryDescription' => $resolution->registryDescription,
            'fixedCourt' => $fixedCourt,
            'candidateKods' => $resolution->candidateCourtKods,
            'infosoudUrl' => $infosoudUrl,
        ]);
    }


    protected function createComponentSpisovkaForm(): Form
    {
        $form = new Form;
        $this->spisovkaInput->addSpisovkaControls($form);
        $form->addSubmit('goDetail', 'Detail spisu');
        $form->addSubmit('goInfosoud', 'Přejít na infoSoud');
        $form->onSuccess[] = $this->spisovkaFormSucceeded(...);
        return $form;
    }


    private function spisovkaFormSucceeded(Form $form, \stdClass $data): void
    {
        try {
            $parsed = $this->parser->parse($data->znacka);
        } catch (SpisovkaParseException $e) {
            $form['znacka']->addError($e->getMessage());
            return;
        }

        $resolution = $this->resolver->resolve($parsed);
        foreach ($resolution->errors as $error) {
            $form['znacka']->addError($error);
        }
        if (!$form->isValid()) {
            return;
        }

        $courtKod = $data->soud ?? $resolution->fixedCourtKod;
        if ($courtKod === null) {
            $form['soud']->addError('Ze značky nelze soud určit – vyberte ho prosím v seznamu.');
            return;
        }

        $court = $this->courts->getByKod($courtKod);
        if ($court === null) {
            $form['soud']->addError('Neznámý soud.');
            return;
        }

        if ($court->level === 'nss') {
            $form['soud']->addError(
                'Řízení Nejvyššího správního soudu infoSoud neobsahuje – sledujte je na www.nssoud.cz.',
            );
            return;
        }

        /** @var \Nette\Forms\Controls\SubmitButton $goDetail */
        $goDetail = $form['goDetail'];
        if ($goDetail->isSubmittedBy()) {
            $this->redirect(':Spis:detail', ['soud' => $court->slug, 'znacka' => SpisovkaSlug::format($parsed)]);
        }

        $url = $this->linkBuilder->detailUrl($parsed, $court);
        assert($url !== null); // NSS handled above
        $this->redirectUrl($url);
    }


    /** Rebuilds the input with a suggested registry so the UI can offer one-click fix. */
    private function buildSuggestionText(ParsedSpisovka $parsed, string $registryCode): string
    {
        $corrected = new ParsedSpisovka(
            courtPrefix: $parsed->courtPrefix,
            senate: $parsed->senate,
            registry: $registryCode,
            number: $parsed->number,
            year: $parsed->year,
            attachedNumber: null,
        );
        return ($corrected->courtPrefix !== null ? $corrected->courtPrefix . ' ' : '') . $corrected->format();
    }
}
