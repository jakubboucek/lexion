<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\Codelist\CourtCodeResolver;
use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudEventType;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Proceeding\ProceedingSyncService;
use App\Model\Spisovka\ParsedSpisovka;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use App\Model\Spisovka\SpisovkaSlug;
use Nette;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;


/**
 * Public case detail: cache-first, at most 2 upstream requests when the case
 * is not cached yet (overview + first event). Related cases are rendered as
 * links only - they are fetched when the user actually opens them.
 */
final class SpisPresenter extends Nette\Application\UI\Presenter
{
    /** Cache older than this shows the stale-data warning. */
    private const string StaleThreshold = '-24 hours';
    /** Manual refresh is ignored when the cache is younger than this. */
    private const string RefreshCooldown = '-5 minutes';

    private ActiveRow $court;
    private ParsedSpisovka $spisovka;
    private ?ActiveRow $proceeding = null;


    public function __construct(
        private readonly CourtRepository $courts,
        private readonly CourtCodeResolver $courtCodes,
        private readonly ProceedingRepository $proceedings,
        private readonly ProceedingSyncService $sync,
        private readonly SpisovkaParser $parser,
        private readonly InfosoudLinkBuilder $linkBuilder,
    ) {
        parent::__construct();
    }


    public function actionDetail(string $soud, string $znacka): void
    {
        $court = $this->courts->getByKod(strtoupper($soud));
        if ($court === null) {
            $this->error('Neznámý soud.');
        }
        $this->court = $court;
        try {
            $this->spisovka = SpisovkaSlug::parse($znacka, $this->parser);
        } catch (SpisovkaParseException $e) {
            $this->error('Neplatná spisová značka: ' . $e->getMessage());
        }

        $this->proceeding = $this->loadProceeding();

        // Cache-first: fetch from infosoud only when we have no infosoud data yet.
        if ($this->proceeding === null || $this->proceeding->infosoud_json === null) {
            $this->fetchFromInfosoud();
        }

        if ($this->proceeding === null) {
            $this->error('Řízení se nepodařilo najít (v cache ani na infoSoudu).');
        }
    }


    /** Manual one-off refresh (per-case cooldown applies). */
    public function handleRefresh(): void
    {
        $at = $this->proceeding?->infosoud_at;
        if ($at !== null && $at > new \DateTimeImmutable(self::RefreshCooldown)) {
            $this->flashMessage('Data byla aktualizována před chvílí, zkuste to později.');
        } else {
            $this->fetchFromInfosoud();
        }
        $this->redirect('this');
    }


    public function renderDetail(): void
    {
        $proceeding = $this->proceeding;
        assert($proceeding !== null); // actionDetail() 404s otherwise

        $infosoud = $proceeding->infosoud_json !== null
            ? Json::decode((string) $proceeding->infosoud_json, forceArrays: true)
            : null;
        $isir = $proceeding->isir_json !== null
            ? Json::decode((string) $proceeding->isir_json, forceArrays: true)
            : null;

        $attributes = [];
        foreach ($infosoud['firstEventDetail']['atributy'] ?? [] as $attribute) {
            $attributes[$attribute['typ']] = $attribute['hodnota'];
        }

        $this->template->court = $this->court;
        $this->template->spisovka = $this->spisovka;
        $this->template->proceeding = $proceeding;
        $this->template->infosoud = $infosoud;
        $this->template->isir = $isir;
        $this->template->subject = ($attributes['PREDM_RIZ'] ?? '-') !== '-' ? $attributes['PREDM_RIZ'] : null;
        $this->template->nsAttributes = array_intersect_key(
            $attributes,
            array_flip(['SENAT', 'SLOZENI_SENATU', 'ODVOL_SOUD', 'PR_VEC_NS']),
        );
        $this->template->events = $infosoud !== null ? $this->buildEvents($infosoud) : [];
        $this->template->related = $this->buildRelated($infosoud, $attributes);
        $this->template->infosoudUrl = $this->linkBuilder->detailUrl($this->spisovka, $this->court);
        $this->template->isStale = $proceeding->infosoud_at !== null
            && $proceeding->infosoud_at < new \DateTimeImmutable(self::StaleThreshold);
    }


    private function loadProceeding(): ?ActiveRow
    {
        return $this->proceedings->getByCase(
            (string) $this->court->kod,
            $this->spisovka->registryNorm(),
            $this->spisovka->senate,
            $this->spisovka->number,
            $this->spisovka->year,
        );
    }


    private function fetchFromInfosoud(): void
    {
        try {
            $row = $this->sync->refreshFromInfosoud($this->court, $this->spisovka);
            if ($row !== null) {
                $this->proceeding = $row;
            } elseif ($this->proceeding !== null) {
                $this->flashMessage('Řízení se na infoSoudu nepodařilo najít; zobrazuji informace z ostatních zdrojů.', 'error');
            }
        } catch (InfosoudApiException) {
            if ($this->proceeding === null) {
                $this->error('InfoSoud je momentálně nedostupný, zkuste to prosím později.', Nette\Http\IResponse::S503_ServiceUnavailable);
            }
            $this->flashMessage('InfoSoud je momentálně nedostupný — zobrazuji poslední známý stav.', 'error');
        }
    }


    /**
     * @param array<mixed> $infosoud
     * @return list<array<string, mixed>>
     */
    private function buildEvents(array $infosoud): array
    {
        $supreme = $this->court->level === 'ns';
        $events = [];
        foreach ($infosoud['udalosti'] ?? [] as $event) {
            $foreign = $this->foreignCaseOf($event['znackaId'] ?? []);
            $events[] = [
                'date' => $event['datum'] ?? null,
                'label' => InfosoudEventType::label((string) ($event['udalost'] ?? ''), $supreme),
                'cancelled' => (bool) ($event['zruseno'] ?? false),
                'foreign' => $foreign,
            ];
        }
        usort($events, static fn(array $a, array $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $events;
    }


    /**
     * Aggregates all three link mechanisms into one de-duplicated list (see
     * docs/infosoud-api.md). Links only - nothing is fetched here.
     *
     * @param array<mixed>|null $infosoud
     * @param array<string, string> $attributes
     * @return list<array<string, mixed>>
     */
    private function buildRelated(?array $infosoud, array $attributes): array
    {
        $related = [];

        foreach ($infosoud['navazneVeci'] ?? [] as $ref) {
            $this->addRelated($related, $ref, 'navazující věc');
        }
        foreach ($infosoud['udalosti'] ?? [] as $event) {
            $foreign = $this->foreignCaseOf($event['znackaId'] ?? []);
            if ($foreign !== null) {
                $this->addRelated($related, $event['znackaId'], InfosoudEventType::label((string) ($event['udalost'] ?? '')));
            }
        }
        if (($attributes['PRED_VEC'] ?? '-') !== '-') {
            try {
                $parsed = $this->parser->parse($attributes['PRED_VEC']);
                // PRED_VEC carries no court. For appeals it points at the
                // subordinate court, so prefer a unique cache match across
                // courts before falling back to "same court".
                $cachedRows = $this->proceedings->findBySpisovka(
                    $parsed->registryNorm(),
                    $parsed->senate,
                    $parsed->number,
                    $parsed->year,
                );
                $courtKod = count($cachedRows) === 1
                    ? (string) $cachedRows[0]->court_kod
                    : (string) $this->court->kod;
                $this->addRelated($related, [
                    'cisloSenatu' => $parsed->senate,
                    'druhVeci' => $parsed->registryNorm(),
                    'bcVec' => $parsed->number,
                    'rocnik' => $parsed->year,
                    'organizace' => $courtKod,
                ], 'předchozí věc');
            } catch (SpisovkaParseException) {
                // leave unparseable references out - nothing to link to
            }
        }

        return array_values($related);
    }


    /**
     * @param array<string, array<string, mixed>> $related
     * @param array<mixed> $ref
     */
    private function addRelated(array &$related, array $ref, string $relation): void
    {
        $rawKod = (string) ($ref['organizace'] ?? '');
        $courtKod = $this->courtCodes->resolveKod($rawKod) ?? $rawKod;
        $parsed = new ParsedSpisovka(
            courtPrefix: null,
            senate: (int) ($ref['cisloSenatu'] ?? 0),
            registry: strtoupper((string) ($ref['druhVeci'] ?? '')),
            number: (int) ($ref['bcVec'] ?? 0),
            year: (int) ($ref['rocnik'] ?? 0),
            attachedNumber: null,
        );
        $key = $courtKod . '|' . $parsed->format();
        if (isset($related[$key])) {
            if (!in_array($relation, $related[$key]['relations'], true)) {
                $related[$key]['relations'][] = $relation;
            }
            return;
        }
        $court = $courtKod !== '' ? $this->courts->getByKod($courtKod) : null;
        $cached = $court !== null && $this->proceedings->getByCase(
            $courtKod,
            $parsed->registryNorm(),
            $parsed->senate,
            $parsed->number,
            $parsed->year,
        ) !== null;
        $related[$key] = [
            'label' => $parsed->format(),
            'courtKod' => $courtKod,
            'courtName' => $court?->name,
            'slug' => SpisovkaSlug::format($parsed),
            'relations' => [$relation],
            'cached' => $cached,
        ];
    }


    /**
     * Returns the foreign case ref when the event belongs to another case.
     *
     * @param array<mixed> $znackaId
     * @return array<string, mixed>|null
     */
    private function foreignCaseOf(array $znackaId): ?array
    {
        if ($znackaId === []) {
            return null;
        }
        // Org codes may be infosoud-internal aliases (NSJIMBM = NS); NS events
        // carry senate 0 instead of the real senate number.
        $resolvedKod = $this->courtCodes->resolveKod((string) ($znackaId['organizace'] ?? ''));
        $senate = (int) ($znackaId['cisloSenatu'] ?? -1);
        $isOwn = $resolvedKod === (string) $this->court->kod
            && ($senate === $this->spisovka->senate || $senate === 0)
            && strtoupper((string) ($znackaId['druhVeci'] ?? '')) === $this->spisovka->registryNorm()
            && (int) ($znackaId['bcVec'] ?? -1) === $this->spisovka->number
            && (int) ($znackaId['rocnik'] ?? -1) === $this->spisovka->year;
        if ($isOwn) {
            return null;
        }
        $courtKod = $resolvedKod ?? (string) ($znackaId['organizace'] ?? '');
        $parsed = new ParsedSpisovka(
            courtPrefix: null,
            senate: max($senate, 0),
            registry: strtoupper((string) ($znackaId['druhVeci'] ?? '')),
            number: (int) ($znackaId['bcVec'] ?? 0),
            year: (int) ($znackaId['rocnik'] ?? 0),
            attachedNumber: null,
        );
        $court = $courtKod !== '' ? $this->courts->getByKod($courtKod) : null;
        return [
            'label' => $parsed->format(),
            'courtKod' => $courtKod,
            'courtName' => $court?->name,
            'slug' => SpisovkaSlug::format($parsed),
        ];
    }
}
