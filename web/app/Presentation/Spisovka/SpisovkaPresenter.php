<?php declare(strict_types=1);

namespace App\Presentation\Spisovka;

use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudLinkBuilder;
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


    /** Rebuilds the input with a suggested registry so the UI can offer one-click fix. */
    private function buildSuggestionText(Spisovka $parsed, string $registryCode): string
    {
        $corrected = $this->spisovkaFactory->fromCase($parsed->senate, $registryCode, $parsed->number, $parsed->year);
        return ($parsed->courtPrefix !== null ? $parsed->courtPrefix . ' ' : '') . $corrected->format();
    }
}
