<?php declare(strict_types=1);

namespace App\Presentation\Spisovka;

use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Proceeding\ProceedingSyncService;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use App\Model\Spisovka\SpisovkaResolver;
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
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CourtRepository $courts,
        private readonly SpisovkaInputFactory $spisovkaInput,
        private readonly ProceedingRepository $proceedings,
        private readonly ProceedingSyncService $sync,
    ) {
        parent::__construct();
    }


    /** Canonical display form (registry from the codelist) of a parsed input. */
    private function canonical(Spisovka $parsed): Spisovka
    {
        return $this->spisovkaFactory->fromCase(
            $parsed->senate,
            $parsed->registryNorm(),
            $parsed->number,
            $parsed->year,
        );
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
            'normalized' => $this->canonical($parsed)->format(),
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
            // Redirect to the detail only once the case is known to exist:
            // check the cache first, otherwise fetch from infosoud right here.
            // The fetch also warms the cache, so the detail page then renders
            // without any further upstream requests.
            if (!$this->proceedingExists($form, $court, $parsed)) {
                return;
            }
            $this->redirect(':Spis:detail', ['soud' => $court->slug, 'znacka' => $parsed->toSlug()]);
        }

        $url = $this->linkBuilder->detailUrl($parsed, $court);
        assert($url !== null); // NSS handled above
        $this->redirectUrl($url);
    }


    /**
     * Verifies the case exists (cache row from any source, or a successful
     * infosoud fetch which also stores it into the cache). On failure adds
     * a form error and returns false.
     */
    private function proceedingExists(Form $form, Nette\Database\Table\ActiveRow $court, Spisovka $parsed): bool
    {
        $cached = $this->proceedings->getByCase(
            (string) $court->kod,
            $parsed->registryNorm(),
            $parsed->senate,
            $parsed->number,
            $parsed->year,
        );
        if ($cached !== null) {
            return true;
        }

        try {
            if ($this->sync->refreshFromInfosoud($court, $parsed) !== null) {
                return true;
            }
        } catch (InfosoudApiException) {
            $form->addError('InfoSoud je momentálně nedostupný, zkuste to prosím později.');
            return false;
        }

        $form->addError('Řízení se nepodařilo najít (v cache ani na infoSoudu) – zkontrolujte značku i soud.');
        return false;
    }


    /** Rebuilds the input with a suggested registry so the UI can offer one-click fix. */
    private function buildSuggestionText(Spisovka $parsed, string $registryCode): string
    {
        $corrected = $this->spisovkaFactory->fromCase($parsed->senate, $registryCode, $parsed->number, $parsed->year);
        return ($parsed->courtPrefix !== null ? $parsed->courtPrefix . ' ' : '') . $corrected->format();
    }
}
