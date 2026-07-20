<?php declare(strict_types=1);

namespace App\Presentation\Home;

use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Proceeding\ProceedingSyncService;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use App\Model\Spisovka\SpisovkaResolver;
use App\Presentation\Accessory\SpisovkaInputFactory;
use Nette;
use Nette\Application\UI\Form;


/**
 * Homepage = the spisovka tool: paste a file number as free text, get
 * validation and either the case detail here or a direct link to infosoud.
 * Live validation reuses the stateless Spisovka:validate JSON endpoint.
 */
final class HomePresenter extends Nette\Application\UI\Presenter
{
    public function __construct(
        private readonly SpisovkaParser $parser,
        private readonly SpisovkaResolver $resolver,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CourtRepository $courts,
        private readonly SpisovkaInputFactory $spisovkaInput,
        private readonly ProceedingRepository $proceedings,
        private readonly ProceedingSyncService $sync,
    ) {
        parent::__construct();
    }


    /**
     * GET params prefill the tool form: the back link from the case detail
     * passes the current case, and the client JS mirrors submitted values
     * into the URL (history.replaceState) right before the form leaves the
     * page, so the browser back button restores the search.
     */
    public function renderDefault(?string $znacka = null, ?string $soud = null): void
    {
        /** @var Form $form */
        $form = $this->getComponent('spisovkaForm');
        if ($form->isSubmitted()) {
            return; // never clobber actually submitted values
        }
        $defaults = [];
        if ($znacka !== null && $znacka !== '') {
            $defaults['znacka'] = $znacka;
        }
        if ($soud !== null && $this->courts->getByKod($soud) !== null) {
            $defaults['soud'] = $soud;
        }
        if ($defaults !== []) {
            $form->setDefaults($defaults);
        }
    }


    protected function createComponentSpisovkaForm(): Form
    {
        $form = new Form;
        $this->spisovkaInput->addSpisovkaControls($form);
        $form->addSubmit('goDetail', 'Otevřít');
        $form->addSubmit('goInfosoud', 'InfoSoud');
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
            // Cache fallback mirroring the live preselect: a single cached
            // match determines the court even without JS.
            $cachedRows = $this->proceedings->findBySpisovka(
                $parsed->registryNorm(),
                $parsed->senate,
                $parsed->number,
                $parsed->year,
            );
            if (count($cachedRows) === 1) {
                $courtKod = (string) $cachedRows[0]->court_kod;
            }
        }
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
                'Spisy Nejvyššího správního soudu zatím neevidujeme – sledujte je na www.nssoud.cz.',
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

        $form->addError('Řízení se nepodařilo najít (v systému ani na infoSoudu) – zkontrolujte značku i soud.');
        return false;
    }
}
