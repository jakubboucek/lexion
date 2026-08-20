<?php declare(strict_types=1);

namespace App\Presentation\Spisovka;

use App\Model\CaseFile\CaseFileSyncService;
use App\Model\CaseFile\CaseLoadOutcome;
use App\Model\CaseFile\CaseLoadPolicy;
use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Spisovka\CourtCandidateService;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use App\Model\Spisovka\SpisovkaResolver;
use Nette;
use Nette\Application\Attributes\Requires;


/**
 * JSON endpoints of the spisovka tool: `validate` answers while the user
 * types, `resolve` answers the submit (where to go). The tool itself is the
 * Vue island rendered by the Home presenter; there is no page under
 * /spisovka.
 */
final class SpisovkaPresenter extends Nette\Application\UI\Presenter
{
    public function __construct(
        private readonly SpisovkaParser $parser,
        private readonly SpisovkaResolver $resolver,
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CourtRepository $courts,
        private readonly CourtCandidateService $courtCandidates,
        private readonly CaseFileSyncService $sync,
    ) {
        parent::__construct();
    }


    /** No page here — the tool lives on the homepage, this presenter only serves validate. */
    public function actionDefault(): never
    {
        $this->error();
    }


    /**
     * Submit endpoint: decides where the tool should send the visitor.
     *
     * Same rules the server-rendered form used to apply on POST - the court
     * fallback, the NSS refusal, and "only link to a case we know exists"
     * (which also warms the record, so the detail page needs no upstream
     * request). Errors come back keyed by field so the island can place them.
     */
    #[Requires(methods: 'POST', sameOrigin: true)]
    public function actionResolve(): never
    {
        $post = $this->getHttpRequest()->getPost();
        $text = is_string($post['text'] ?? null) ? $post['text'] : '';
        $courtKod = is_string($post['soud'] ?? null) && $post['soud'] !== '' ? $post['soud'] : null;
        $action = ($post['action'] ?? null) === 'infosoud' ? 'infosoud' : 'detail';

        try {
            $parsed = $this->parser->parse($text);
        } catch (SpisovkaParseException $e) {
            $this->sendJson(['ok' => false, 'errors' => ['znacka' => [$e->getMessage()]]]);
        }

        $resolution = $this->resolver->resolve($parsed);
        if ($resolution->errors !== []) {
            $this->sendJson(['ok' => false, 'errors' => ['znacka' => $resolution->errors]]);
        }

        $courtKod ??= $resolution->fixedCourtKod;
        if ($courtKod === null) {
            // The rule the old form applied when JS did not preselect: the
            // shared candidate service decides when it is unambiguous.
            $sole = $this->courtCandidates->candidatesFor($parsed)->sole();
            $courtKod = $sole !== null ? (string) $sole->kod : null;
        }
        if ($courtKod === null) {
            $this->sendJson(['ok' => false, 'errors' => ['soud' => ['Ze značky nelze soud určit – vyberte ho prosím v seznamu.']]]);
        }

        $court = $this->courts->getByKod($courtKod);
        if ($court === null) {
            $this->sendJson(['ok' => false, 'errors' => ['soud' => ['Neznámý soud.']]]);
        }
        if (!$court->level->isOnInfosoud()) {
            $this->sendJson(['ok' => false, 'errors' => ['soud' => [
                'Spisy Nejvyššího správního soudu zatím neevidujeme – sledujte je na www.nssoud.cz.',
            ]]]);
        }

        if ($action === 'infosoud') {
            $url = $this->linkBuilder->detailUrl($parsed, $court);
            assert($url !== null); // NSS refused above
            $this->sendJson(['ok' => true, 'redirect' => $url]);
        }

        $loaded = $this->sync->ensureLoaded($court, $parsed, CaseLoadPolicy::AnySource);
        if ($loaded->case === null) {
            $this->sendJson(['ok' => false, 'errors' => ['form' => [
                $loaded->outcome === CaseLoadOutcome::Unavailable
                    ? 'InfoSoud je momentálně nedostupný, zkuste to prosím později.'
                    : 'Řízení se nepodařilo najít (v systému ani na infoSoudu) – zkontrolujte značku i soud.',
            ]]]);
        }

        $this->sendJson([
            'ok' => true,
            'redirect' => $this->link(':Spis:detail', ['soud' => $court->slug, 'znacka' => $parsed->toSlug()]),
        ]);
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
                    'kod' => $court->kod,
                    'name' => $court->name,
                    'reason' => $resolution->fixedCourtReason,
                ];
                $infosoudUrl = $this->linkBuilder->detailUrl($parsed, $court);
            }
        }

        // Shared candidate rule (cache first, hearings only when the cache is
        // silent - see CourtCandidateService); the UI preselects on a single
        // match and the options are never constrained.
        $candidates = $this->courtCandidates->candidatesFor($parsed);
        $cachedCourts = array_map(
            static fn(Court $court): array => ['kod' => $court->kod, 'name' => $court->name],
            $candidates->cachedCourts,
        );
        $hearingCourts = array_map(
            static fn(array $item) => [
                'kod' => $item['court']->kod,
                'name' => $item['court']->name,
                'hearings' => $item['hearings'],
            ],
            $candidates->hearingCourts,
        );

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
            'cachedCourts' => $cachedCourts,
            'hearingCourts' => $hearingCourts,
            'infosoudUrl' => $infosoudUrl,
        ]);
    }


    /** Rebuilds the input with a suggested registry so the UI can offer one-click fix. */
    private function buildSuggestionText(Spisovka $parsed, string $registryCode): string
    {
        $corrected = $this->spisovkaFactory->fromCase($parsed->senate, $registryCode, $parsed->number, $parsed->year);
        return ($parsed->courtPrefix !== null ? $parsed->courtPrefix . ' ' : '') . $corrected->format();
    }
}
