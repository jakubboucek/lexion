<?php declare(strict_types=1);

namespace App\Presentation\Spisovka;

use App\Model\Codelist\CourtRepository;
use App\Model\Hearing\HearingRepository;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use App\Model\Spisovka\SpisovkaResolver;
use Nette;
use Nette\Application\Attributes\Requires;


/**
 * Stateless JSON validation endpoint reused by the spisovka input component
 * (assets/spisovka-input.js). The interactive tool itself lives on the
 * homepage (Home presenter); there is no page under /spisovka.
 */
final class SpisovkaPresenter extends Nette\Application\UI\Presenter
{
    public function __construct(
        private readonly SpisovkaParser $parser,
        private readonly SpisovkaResolver $resolver,
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CourtRepository $courts,
        private readonly ProceedingRepository $proceedings,
        private readonly HearingRepository $hearings,
    ) {
        parent::__construct();
    }


    /** No page here — the tool lives on the homepage, this presenter only serves validate. */
    public function actionDefault(): never
    {
        $this->error();
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

        // Courts where the cache already holds this case - the UI preselects
        // the court on a single match. The cache is not authoritative (it may
        // miss the case elsewhere), so this never constrains the options.
        $cachedCourts = [];
        foreach ($this->proceedings->findBySpisovka($parsed->registryNorm(), $parsed->senate, $parsed->number, $parsed->year) as $row) {
            $cachedCourt = $this->courts->getByKod((string) $row->court_kod);
            if ($cachedCourt !== null) {
                $cachedCourts[] = ['kod' => (string) $cachedCourt->kod, 'name' => (string) $cachedCourt->name];
            }
        }

        // Courts where a hearing with this file number is on record. Weaker
        // than the cache above (it is the court of the ROOM, not necessarily
        // the court the case is filed at), so it only fills in when the cache
        // knows nothing - and it never constrains the options either.
        $hearingCourts = [];
        if ($cachedCourts === []) {
            $counts = $this->hearings->countPerVenueBySpisovka(
                $parsed->registryNorm(),
                $parsed->senate,
                $parsed->number,
                $parsed->year,
            );
            foreach ($counts as $kod => $count) {
                $hearingCourt = $this->courts->getByKod((string) $kod);
                if ($hearingCourt !== null) {
                    $hearingCourts[] = [
                        'kod' => (string) $hearingCourt->kod,
                        'name' => (string) $hearingCourt->name,
                        'hearings' => $count,
                    ];
                }
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
