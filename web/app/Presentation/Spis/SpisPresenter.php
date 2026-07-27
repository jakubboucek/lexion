<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\Codelist\CourtRepository;
use App\Model\Favorite\FavoriteRepository;
use App\Model\Codelist\RegistryRepository;
use App\Model\Codelist\RelationTypeRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Codelist\CourtLevel;
use App\Model\Infosoud\InfosoudCollegium;
use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Infosoud\InfosoudEventType;
use App\Model\Infosoud\InfosoudHearing;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\CaseSummaryService;
use App\Model\Proceeding\ProceedingEventRepository;
use App\Model\Proceeding\ProceedingRelationRepository;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Proceeding\ProceedingSyncService;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use App\Model\Spisovka\SpisovkaSlugParser;
use Nette;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;


/**
 * Public case detail: cache-first, at most 2 upstream requests when the case
 * is not cached yet (overview + first event). Related cases are rendered as
 * links only - they are fetched when the user actually opens them.
 *
 * The URL slug is only a lookup key: it is parsed into a local Spisovka to find
 * the case, and the Spisovka used for rendering is rebuilt from the cached DB
 * row (its display form comes from the codelist).
 *
 * Events and relations render from the projected tables (proceeding_event /
 * proceeding_relation, see docs/analyza-udalosti.md); the event detail page
 * addresses rows by their surrogate id and lazily fetches the upstream detail.
 */
final class SpisPresenter extends Nette\Application\UI\Presenter
{
    /** Cache older than this shows the stale-data warning. */
    private const string StaleThreshold = '-1 month';
    /** Manual refresh is ignored when the cache is younger than this. */
    private const string RefreshCooldown = '-5 minutes';

    private ActiveRow $court;
    private Spisovka $spisovka;      // canonical, built from the DB row
    private ?ActiveRow $proceeding = null;
    private ?ActiveRow $event = null;


    public function __construct(
        private readonly CourtRepository $courts,
        private readonly RegistryRepository $registries,
        private readonly RelationTypeRepository $relationTypes,
        private readonly ProceedingRepository $proceedings,
        private readonly ProceedingEventRepository $events,
        private readonly ProceedingRelationRepository $relations,
        private readonly ProceedingSyncService $sync,
        private readonly CaseSummaryService $caseSummary,
        private readonly FavoriteRepository $favorites,
        private readonly InfosoudClient $client,
        private readonly SpisovkaParser $parser,
        private readonly SpisovkaSlugParser $slugParser,
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly InfosoudLinkBuilder $linkBuilder,
    ) {
        parent::__construct();
    }


    public function actionDetail(string $soud, string $znacka): void
    {
        $ref = $this->resolveCase($soud, $znacka, 'detail');

        $this->proceeding = $this->loadProceeding($ref);

        // Cache-first: fetch from infosoud only when we have no infosoud data yet.
        if ($this->proceeding === null || $this->proceeding->infosoud_json === null) {
            $this->fetchFromInfosoud($ref);
        }

        if ($this->proceeding === null) {
            $this->error('Řízení se nepodařilo najít (v systému ani na infoSoudu).');
        }

        // The Spisovka used from here on is the authoritative one from the DB.
        $this->spisovka = $this->spisovkaFactory->fromProceeding($this->proceeding);
    }


    public function actionUdalost(string $soud, string $znacka, int $id): void
    {
        $ref = $this->resolveCase($soud, $znacka, 'udalost', ['id' => $id]);

        // Event pages exist only for already-loaded cases; ids would not match
        // anything otherwise, so no upstream fetch here.
        $this->proceeding = $this->loadProceeding($ref);
        if ($this->proceeding === null) {
            $this->error('Řízení neevidujeme.');
        }
        $this->spisovka = $this->spisovkaFactory->fromProceeding($this->proceeding);

        $event = $this->events->getById($id);
        if ($event === null || (int) $event->proceeding_id !== (int) $this->proceeding->id) {
            $this->error('Neznámá událost.');
        }
        $this->event = $event;

        if ($event->detail_fetched_at === null) {
            $this->fetchEventDetail();
        }
    }


    /** Manual one-off refresh (per-case cooldown applies). */
    public function handleRefresh(): void
    {
        $at = $this->proceeding?->infosoud_at;
        if ($at !== null && $at > new \DateTimeImmutable(self::RefreshCooldown)) {
            $this->flashMessage('Data byla aktualizována před chvílí, zkuste to později.');
        } else {
            $this->fetchFromInfosoud($this->spisovka);
        }
        $this->redirect('this');
    }


    /** Fetches one event's detail from the case timeline, staying on the timeline. */
    public function handleFetchEvent(int $id): void
    {
        $event = $this->events->getById($id);
        if ($event === null || $this->proceeding === null
            || (int) $event->proceeding_id !== (int) $this->proceeding->id) {
            $this->error('Neznámá událost.');
        }
        $this->event = $event;
        if ($event->detail_fetched_at === null) {
            $this->fetchEventDetail();
        }
        $this->redirect('this');
    }


    /** Manual refresh of one event detail (per-event cooldown applies). */
    public function handleRefreshEvent(): void
    {
        $at = $this->event?->detail_fetched_at;
        if ($at instanceof \DateTimeInterface && $at > new \DateTimeImmutable(self::RefreshCooldown)) {
            $this->flashMessage('Detail události byl aktualizován před chvílí, zkuste to později.');
        } else {
            $this->fetchEventDetail();
        }
        $this->redirect('this');
    }


    public function renderDetail(): void
    {
        $this->assignCaseHeader();
        // Records with no date (e.g. the NS "Poznámka o procesních opatřeních")
        // cannot be placed in the timeline, so they get their own box below it
        // instead of floating to the top of the table.
        $events = $this->buildEventsView();
        $this->template->events = array_values(array_filter($events, static fn(array $e): bool => $e['date'] !== null));
        $this->template->undatedEvents = array_values(array_filter($events, static fn(array $e): bool => $e['date'] === null));
        $this->template->related = $this->buildRelatedView();
        $this->template->infosoudUrl = $this->linkBuilder->detailUrl($this->spisovka, $this->court);
    }


    /** Template variables of the shared case header (see @case-header.latte). */
    private function assignCaseHeader(): void
    {
        $proceeding = $this->proceeding;
        assert($proceeding !== null); // both actions 404 otherwise

        $infosoud = $proceeding->infosoud_json !== null
            ? Json::decode((string) $proceeding->infosoud_json, forceArrays: true)
            : null;
        $isir = $proceeding->isir_json !== null
            ? Json::decode((string) $proceeding->isir_json, forceArrays: true)
            : null;

        $attributes = $this->caseSummary->attributesOf($proceeding);

        // Display form of the file number comes from the codelist-backed Spisovka.
        $this->template->court = $this->court;
        $this->template->spisovkaLabel = $this->spisovka->format();
        $this->template->caseSlug = $this->spisovka->toSlug();
        $this->template->proceeding = $proceeding;
        $this->template->infosoud = $infosoud;
        $this->template->isir = $isir;
        $this->template->subject = $this->caseSummary->subjectFrom($attributes);
        $nsAttributes = array_filter(array_intersect_key(
            $attributes,
            array_flip(['SENAT', 'SLOZENI_SENATU', 'ODVOL_SOUD', 'PR_VEC_NS']),
        ), static fn(?string $value): bool => $value !== null);
        $this->template->nsAttributes = $nsAttributes;
        // The file number under review renders as the usual chip; its court is
        // the one named in ODVOL_SOUD (see buildAttributesView).
        $challenged = ($nsAttributes['PR_VEC_NS'] ?? null) !== null
            ? $this->resolveCaseReferences(
                [$nsAttributes['PR_VEC_NS']],
                $this->relatedCourtIndex($proceeding),
                $this->courtNamedIn($nsAttributes, 'PR_VEC_NS'),
            )
            : null;
        $this->template->nsChallenged = $challenged[0] ?? null;
        // Supreme Court cases carry no state; the SPA shows the collegium there.
        $this->template->collegium = $this->courtLevel() === CourtLevel::Supreme
            ? InfosoudCollegium::forRegistry($this->spisovka->registryNorm())
            : null;
        $this->template->isStale = $proceeding->infosoud_at !== null
            && $proceeding->infosoud_at < new \DateTimeImmutable(self::StaleThreshold);
        $this->template->favorite = $this->currentFavorite();
    }


    /** Level of the case's court (the URL court). */
    private function courtLevel(): CourtLevel
    {
        return CourtLevel::from((string) $this->court->level);
    }


    /** The logged-in user's favorite row of the current case, if any. */
    private function currentFavorite(): ?ActiveRow
    {
        if (!$this->getUser()->isLoggedIn() || $this->proceeding === null) {
            return null;
        }
        return $this->favorites->getByUserAndProceeding((int) $this->getUser()->getId(), (int) $this->proceeding->id);
    }


    /** Add-to-favorites form shown in the star modal (see @case-header.latte). */
    protected function createComponentFavoriteForm(): Form
    {
        $form = new Form;
        $form->addText('name', 'Vlastní název')
            ->setNullable()
            ->addRule($form::MaxLength, 'Název může mít nejvýše %d znaků.', 255);
        $form->addSubmit('send', 'Přidat do oblíbených');
        $form->onSuccess[] = $this->favoriteFormSucceeded(...);
        return $form;
    }


    private function favoriteFormSucceeded(Form $form, \stdClass $data): void
    {
        if (!$this->getUser()->isLoggedIn()) {
            $this->error('Přihlášení je vyžadováno.', Nette\Http\IResponse::S403_Forbidden);
        }
        assert($this->proceeding !== null); // actions 404 otherwise
        if ($this->currentFavorite() === null) {
            $this->favorites->add((int) $this->getUser()->getId(), (int) $this->proceeding->id, $data->name);
            $this->flashMessage('Spis byl přidán do oblíbených.');
        } else {
            $this->flashMessage('Spis už ve svých oblíbených máte.');
        }
        $this->redirect('this');
    }


    /** Removes the case from the user's favorites (confirmed by a modal). */
    public function handleRemoveFavorite(): void
    {
        if (!$this->getUser()->isLoggedIn()) {
            $this->error('Přihlášení je vyžadováno.', Nette\Http\IResponse::S403_Forbidden);
        }
        $favorite = $this->currentFavorite();
        if ($favorite !== null) {
            $this->favorites->delete($favorite);
            $this->flashMessage('Spis byl odebrán z oblíbených.');
        }
        $this->redirect('this');
    }


    public function renderUdalost(): void
    {
        $event = $this->event;
        assert($event !== null); // actionUdalost() 404s otherwise

        $this->assignCaseHeader();

        // Labels follow the flavor of the court owning the record (a foreign
        // NS event in a KS timeline uses the NS wording).
        $ownerCourt = $event->ref_court_kod !== null
            ? $this->courts->getByKod((string) $event->ref_court_kod)
            : $this->court;
        $ownerLevel = CourtLevel::from($ownerCourt !== null ? (string) $ownerCourt->level : (string) $this->court->level);
        $code = (string) $event->event_code;

        $detail = $event->detail_json !== null
            ? Json::decode((string) $event->detail_json, forceArrays: true)
            : null;

        $owner = null;
        if ($event->ref_registry_norm !== null) {
            $ownerSpisovka = $this->spisovkaFactory->fromEventRef($event);
            $owner = $this->caseChip($ownerCourt, $ownerSpisovka);
        }

        $this->template->event = $event;
        $this->template->eventLabel = InfosoudEventType::label($code, $ownerLevel);
        $this->template->eventDescription = InfosoudEventType::description($code, $ownerLevel);
        $this->template->owner = $owner;
        assert($this->proceeding !== null); // actionUdalost() 404s otherwise
        $this->template->attributes = $this->buildAttributesView($detail, $ownerLevel, $this->relatedCourtIndex($this->proceeding));
        $this->template->navazneVeci = $this->buildNavazneView($detail);
        $this->template->navazneFirst = $code === 'DOVOL_RIZ'; // SPA renders them above attributes for DOVOL_RIZ
        $this->template->eventInfosoudUrl = $this->buildEventInfosoudUrl($event);
        $this->template->eventFetchable = $this->hasUpstreamAddress($event);
    }


    /**
     * Whether the record carries an upstream address fetchEventDetail() can
     * query: a `poradi`, and for a foreign record also the owner court. Rows
     * without one are permanent thin records - the UI must not offer fetching
     * or promise the detail will appear later.
     */
    private function hasUpstreamAddress(ActiveRow $event): bool
    {
        return $event->event_order !== null
            && ($event->ref_registry_norm === null || $event->ref_court_kod !== null);
    }


    /**
     * Case + slug resolution shared by both actions: court slug is canonical,
     * the raw infosoud code still resolves (old links) but redirects.
     *
     * @param array<string, mixed> $extraParams
     */
    private function resolveCase(string $soud, string $znacka, string $action, array $extraParams = []): Spisovka
    {
        $court = $this->courts->getBySlug($soud) ?? $this->courts->getByKod(strtoupper($soud));
        if ($court === null) {
            $this->error('Neznámý soud.');
        }
        $this->court = $court;

        // Parse the slug locally, only as a lookup key.
        try {
            $ref = $this->slugParser->parse($znacka);
        } catch (SpisovkaParseException $e) {
            $this->error('Neplatná spisová značka: ' . $e->getMessage());
        }

        // Canonicalize the URL (court slug + lowercase file number) with a 301.
        if ($soud !== (string) $court->slug || $znacka !== $ref->toSlug()) {
            $this->redirectPermanent(
                $action,
                ['soud' => (string) $court->slug, 'znacka' => $ref->toSlug()] + $extraParams,
            );
        }

        return $ref;
    }


    private function loadProceeding(Spisovka $ref): ?ActiveRow
    {
        return $this->proceedings->getByCase((string) $this->court->kod, $ref);
    }


    private function fetchFromInfosoud(Spisovka $ref): void
    {
        try {
            $row = $this->sync->refreshFromInfosoud($this->court, $ref);
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
     * Lazily fetches the upstream event detail into the row and verifies the
     * record still matches (a renumbered poradi shows up as a different type
     * or date -> data-integrity flow, see docs/analyza-udalosti.md).
     */
    private function fetchEventDetail(): void
    {
        $event = $this->event;
        assert($event !== null);
        if ($event->event_order === null) {
            return; // no upstream address for this record
        }

        // The case whose sequence owns the record: the case itself, or the
        // foreign case (appeals etc.) from the ref columns.
        if ($event->ref_registry_norm === null) {
            $court = $this->court;
            $spisovka = $this->spisovka;
        } else {
            $court = $event->ref_court_kod !== null ? $this->courts->getByKod((string) $event->ref_court_kod) : null;
            if ($court === null) {
                return; // unknown owner court - keep the thin row
            }
            $spisovka = $this->spisovkaFactory->fromEventRef($event);
        }

        try {
            $detail = $this->client->fetchEventDetail(
                $court,
                $spisovka,
                (string) $event->event_code,
                (int) $event->event_order,
                upstreamId: $event->upstream_id !== null ? (string) $event->upstream_id : null,
            );
        } catch (InfosoudApiException) {
            $this->flashMessage('InfoSoud je momentálně nedostupný — detail události se nepodařilo načíst.', 'error');
            return;
        }

        $now = new \DateTimeImmutable;
        if ($detail === null) {
            // Upstream has no detail for this record; remember that so the
            // page does not retry on every view.
            $this->events->update((int) $event->id, ['detail_json' => null, 'detail_fetched_at' => $now]);
            $this->event = $this->events->getById((int) $event->id);
            return;
        }

        $rowDate = $event->event_date instanceof \DateTimeInterface ? $event->event_date->format('Y-m-d') : null;
        $detailDate = \DateTimeImmutable::createFromFormat('!d.m.Y', (string) ($detail['datumUdalost'] ?? ''));
        $typeMatches = (string) ($detail['typUdalosti'] ?? '') === (string) $event->event_code;
        $dateMatches = $rowDate === null
            || ($detailDate !== false && $detailDate->format('Y-m-d') === $rowDate);
        if (!$typeMatches || !$dateMatches) {
            $this->flashMessage(
                'U tohoto spisu jsme zjistili narušení integrity dat (události se na infoSoudu přečíslovaly). '
                . 'Proveďte prosím aktualizaci spisu — odkazy na události se poté obnoví.',
                'error',
            );
            $this->redirect('detail', ['soud' => (string) $this->court->slug, 'znacka' => $this->spisovka->toSlug()]);
        }

        $this->events->update((int) $event->id, [
            'detail_json' => Json::encode($detail),
            'detail_fetched_at' => $now,
        ]);
        $this->event = $this->events->getById((int) $event->id);
    }


    /** @return list<array<string, mixed>> */
    private function buildEventsView(): array
    {
        assert($this->proceeding !== null);
        $level = $this->courtLevel();
        $today = new \DateTimeImmutable('today');
        $items = [];
        foreach ($this->events->findByProceeding((int) $this->proceeding->id) as $row) {
            $foreign = null;
            if ($row->ref_registry_norm !== null) {
                $court = $row->ref_court_kod !== null ? $this->courts->getByKod((string) $row->ref_court_kod) : null;
                $spisovka = $this->spisovkaFactory->fromEventRef($row);
                $foreign = $this->caseChip($court, $spisovka);
            }

            // Interim hearing info parsed from the NAR_JED detail (hearings
            // will later be scraped separately, see docs/infosoud-api.md).
            $isHearing = (string) $row->event_code === 'NAR_JED';
            $cancelled = (bool) $row->cancelled;
            $hearing = null;
            if ($isHearing && !$cancelled && $row->detail_json !== null) {
                $detail = Json::decode((string) $row->detail_json, forceArrays: true);
                $hearing = is_array($detail) ? InfosoudHearing::fromEventDetail($detail) : null;
            }

            $items[] = [
                'id' => (int) $row->id,
                'date' => $row->event_date,
                'label' => InfosoudEventType::label((string) $row->event_code, $level),
                'cancelled' => $cancelled,
                'hasDetail' => $row->detail_fetched_at !== null,
                'foreign' => $foreign,
                'hearing' => $hearing,
                'hearingFetchable' => $isHearing && !$cancelled && $row->detail_fetched_at === null
                    && $this->hasUpstreamAddress($row),
                'upcoming' => $isHearing && !$cancelled
                    && $row->event_date instanceof \DateTimeInterface && $row->event_date >= $today,
            ];
        }
        return $items;
    }


    /**
     * Relations of the case from both directions of the N:M table, grouped by
     * the other side's identity; the direction picks label vs. label_reverse.
     *
     * @return list<array<string, mixed>>
     */
    private function buildRelatedView(): array
    {
        assert($this->proceeding !== null);
        $p = $this->proceeding;
        $types = $this->relationTypes->findAll();
        $items = [];

        $push = function (?string $courtKod, string $registryNorm, int $senate, int $bcNumber, int $year, string $relationLabel) use (&$items): void {
            $key = ($courtKod ?? '') . '|' . $registryNorm . '|' . $senate . '|' . $bcNumber . '|' . $year;
            if (!isset($items[$key])) {
                $court = $courtKod !== null ? $this->courts->getByKod($courtKod) : null;
                $spisovka = $this->spisovkaFactory->fromCase($senate, $registryNorm, $bcNumber, $year);
                $cachedRow = $court !== null
                    ? $this->proceedings->getByCase((string) $court->kod, $spisovka)
                    : null;
                $items[$key] = [
                    'relations' => [],
                    'cached' => $cachedRow !== null,
                    // cache-only enrichment, never an upstream request
                    'subject' => $cachedRow !== null ? $this->caseSummary->subjectOf($cachedRow) : null,
                ] + $this->caseChip($court, $spisovka);
            }
            if (!in_array($relationLabel, $items[$key]['relations'], true)) {
                $items[$key]['relations'][] = $relationLabel;
            }
        };

        // Senate null for the Supreme Court: other courts' projections record a
        // reference to a NS case with senate 0 instead of the real number, so
        // matching on it would hide every relation pointing at this case.
        $identity = [
            (string) $p->court_kod, (string) $p->registry_norm,
            $this->courtLevel() === CourtLevel::Supreme ? null : (int) $p->senate,
            (int) $p->bc_number, (int) $p->year,
        ];
        foreach ($this->relations->findBySrc(...$identity) as $rel) {
            $push(
                $rel->dst_court_kod !== null ? (string) $rel->dst_court_kod : null,
                (string) $rel->dst_registry_norm,
                (int) $rel->dst_senate,
                (int) $rel->dst_bc_number,
                (int) $rel->dst_year,
                $types[(string) $rel->relation_type]['label'] ?? (string) $rel->relation_type,
            );
        }
        foreach ($this->relations->findByDst(...$identity) as $rel) {
            $push(
                (string) $rel->src_court_kod,
                (string) $rel->src_registry_norm,
                (int) $rel->src_senate,
                (int) $rel->src_bc_number,
                (int) $rel->src_year,
                $types[(string) $rel->relation_type]['labelReverse'] ?? (string) $rel->relation_type,
            );
        }

        return array_values($items);
    }


    /**
     * Attribute rows for the event detail, mirroring the SPA rendering rules
     * (flag attribute, "|" separators, "-" = not stated).
     *
     * @param array<mixed>|null $detail
     * @param array<string, ?string> $relatedCourts identity key => court kod
     * @return list<array<string, mixed>>
     */
    private function buildAttributesView(?array $detail, CourtLevel $ownerLevel, array $relatedCourts): array
    {
        // Flat map of the detail's attributes, so a case reference can read the
        // sibling attribute naming its court (PR_VEC_NS -> ODVOL_SOUD).
        $values = InfosoudEventAttribute::mapFromDetail($detail ?? []);

        $items = [];
        foreach ($detail['atributy'] ?? [] as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $type = (string) ($attribute['typ'] ?? '');
            $value = InfosoudEventAttribute::cleanValue($attribute['hodnota'] ?? null);
            if ($type === '') {
                continue;
            }
            if ($type === InfosoudEventAttribute::FlagAttribute) {
                if ($value === InfosoudEventAttribute::FlagTrue) {
                    $items[] = ['label' => InfosoudEventAttribute::label($type, $ownerLevel), 'value' => null];
                }
                continue;
            }
            if ($value === null) {
                continue; // not stated
            }
            $parts = array_map(trim(...), explode('|', $value));
            $items[] = [
                'label' => InfosoudEventAttribute::label($type, $ownerLevel),
                'value' => implode(', ', $parts),
                'cases' => InfosoudEventAttribute::isCaseReference($type)
                    ? $this->resolveCaseReferences($parts, $relatedCourts, $this->courtNamedIn($values, $type))
                    : null,
            ];
        }
        return $items;
    }


    /**
     * Court named by the sibling attribute of a case reference, if the codelist
     * knows it under that name.
     *
     * @param array<string, ?string> $values attribute type => cleaned value
     */
    private function courtNamedIn(array $values, string $type): ?ActiveRow
    {
        $namedBy = InfosoudEventAttribute::courtNamedBy($type);
        $name = $namedBy !== null ? ($values[$namedBy] ?? null) : null;
        return $name !== null ? $this->courts->getByName($name) : null;
    }


    /**
     * View model of a referenced case, rendered by @spisovka.latte's case-chip.
     *
     * One rule for every place a file number of another case appears: link to
     * its detail when the court is known, otherwise - as long as the registry
     * says it is a court case at all - offer it prefilled on the homepage
     * search, because we cannot address a case without its court. A reference
     * that is not a court case (a prosecutor file) gets no link at all.
     *
     * @return array<string, mixed>
     */
    private function caseChip(?ActiveRow $court, Spisovka $spisovka): array
    {
        $isCourtCase = $this->isCourtRegistry($spisovka);
        return [
            'label' => $spisovka->format(),
            'courtSlug' => $court !== null ? (string) $court->slug : null,
            'courtName' => $court?->name,
            'slug' => $spisovka->toSlug(),
            'linkable' => $court !== null && $isCourtCase,
            'search' => $court === null && $isCourtCase ? $spisovka->format() : null,
        ];
    }


    /**
     * Turns file numbers quoted in an event attribute into chips.
     *
     * The value is upstream free text, so it is only treated as a case when it
     * parses AND its registry is a court one - "2 ZT 7 / 2025" is a prosecutor
     * file and stays plain text, exactly as it does in the related-cases table.
     *
     * The registry is canonicalised ("NC" -> "Nc") ONLY for a file number the
     * case is already known to be related to: there the codelist form is
     * certain. Otherwise the number is merely tidied up (separators, spacing)
     * and offered as a search on the homepage, since we do not know its court.
     *
     * @param list<string> $parts
     * @param array<string, ?string> $relatedCourts identity key => court kod (null = court unknown)
     * @return list<array<string, mixed>>|null null when nothing resolved to a case
     */
    private function resolveCaseReferences(array $parts, array $relatedCourts, ?ActiveRow $courtHint = null): ?array
    {
        $cases = [];
        foreach ($parts as $part) {
            try {
                $parsed = $this->parser->parse($part);
            } catch (SpisovkaParseException) {
                $cases[] = ['text' => $part];
                continue;
            }
            if (!$this->isCourtRegistry($parsed)) {
                $cases[] = ['text' => $part]; // not a court case (prosecutor file, ...)
                continue;
            }

            $key = $parsed->registryNorm() . '|' . $parsed->senate . '|' . $parsed->number . '|' . $parsed->year;
            $isRelated = array_key_exists($key, $relatedCourts);
            $courtKod = $relatedCourts[$key] ?? null;
            // A sibling attribute may name the court (PR_VEC_NS is the file
            // number at the court named in ODVOL_SOUD), which is the only way
            // to resolve it before the case itself is known to us.
            $court = $courtKod !== null ? $this->courts->getByKod($courtKod) : $courtHint;
            // Codelist display form only where we know which case this is.
            $spisovka = $isRelated || $court !== null
                ? $this->spisovkaFactory->fromCase($parsed->senate, $parsed->registryNorm(), $parsed->number, $parsed->year)
                : $parsed;

            $cases[] = $this->caseChip($court, $spisovka);
        }
        return array_any($cases, static fn(array $case): bool => isset($case['label'])) ? $cases : null;
    }


    /**
     * Identity keys of every case this one is related to, mapped to the court
     * kod (null when the relation carries no court).
     *
     * @return array<string, ?string>
     */
    private function relatedCourtIndex(ActiveRow $proceeding): array
    {
        $identity = [
            (string) $proceeding->court_kod, (string) $proceeding->registry_norm,
            $this->courtLevel() === CourtLevel::Supreme ? null : (int) $proceeding->senate,
            (int) $proceeding->bc_number, (int) $proceeding->year,
        ];
        $index = [];
        foreach ($this->relations->findBySrc(...$identity) as $rel) {
            $key = $rel->dst_registry_norm . '|' . $rel->dst_senate . '|' . $rel->dst_bc_number . '|' . $rel->dst_year;
            $index[$key] ??= $rel->dst_court_kod !== null ? (string) $rel->dst_court_kod : null;
        }
        foreach ($this->relations->findByDst(...$identity) as $rel) {
            $key = $rel->src_registry_norm . '|' . $rel->src_senate . '|' . $rel->src_bc_number . '|' . $rel->src_year;
            $index[$key] ??= (string) $rel->src_court_kod;
        }
        return $index;
    }


    /**
     * Linked cases from the event detail's navazneVeci (e.g. the appellate
     * case of an ODVOLANI event).
     *
     * @param array<mixed>|null $detail
     * @return list<array<string, mixed>>
     */
    private function buildNavazneView(?array $detail): array
    {
        $items = [];
        foreach ($detail['navazneVeci'] ?? [] as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $registryNorm = strtoupper((string) ($ref['druh'] ?? ''));
            $bcNumber = (int) ($ref['bcVec'] ?? 0);
            if ($registryNorm === '' || $bcNumber === 0) {
                continue;
            }
            $kod = (string) ($ref['organizace'] ?? '');
            $court = $kod !== '' ? $this->courts->getByKod($kod) : null;
            $spisovka = $this->spisovkaFactory->fromCase(
                (int) ($ref['cislo'] ?? 0),
                $registryNorm,
                $bcNumber,
                CaseYear::fromUpstream((int) ($ref['rocnik'] ?? 0)),
            );
            $cached = $court !== null
                && $this->proceedings->getByCase((string) $court->kod, $spisovka) !== null;
            $items[] = [
                'typeLabel' => InfosoudEventAttribute::label((string) ($ref['typ'] ?? ''), $this->courtLevel()),
                'cached' => $cached,
            ] + $this->caseChip($court, $spisovka);
        }
        return $items;
    }


    /** SPA deep-link of the event (null when it cannot be addressed upstream). */
    private function buildEventInfosoudUrl(ActiveRow $event): ?string
    {
        if ($event->event_order === null) {
            return null;
        }
        $owner = null;
        $ownerCourt = null;
        if ($event->ref_registry_norm !== null) {
            $ownerCourt = $event->ref_court_kod !== null ? $this->courts->getByKod((string) $event->ref_court_kod) : null;
            if ($ownerCourt === null) {
                return null;
            }
            $owner = $this->spisovkaFactory->fromEventRef($event);
        }
        return $this->linkBuilder->eventDetailUrl(
            $this->spisovka,
            $this->court,
            (string) $event->event_code,
            (int) $event->event_order,
            $owner,
            $ownerCourt,
            $event->upstream_id !== null ? (string) $event->upstream_id : null,
        );
    }


    /**
     * A registry missing from the codelist cannot belong to a court case
     * (prosecutor's files etc.) - such a reference must not become a link,
     * the target detail could never exist.
     */
    private function isCourtRegistry(Spisovka $spisovka): bool
    {
        return $this->registries->displayFromNorm($spisovka->registryNorm()) !== null;
    }
}
