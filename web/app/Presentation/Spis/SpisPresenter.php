<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Favorite\Favorite;
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
use App\Model\Proceeding\CaseFile;
use App\Model\Proceeding\CaseFileEvent;
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

    private Court $court;
    private Spisovka $spisovka;      // canonical, built from the DB row
    private ?CaseFile $proceeding = null;
    private ?CaseFileEvent $event = null;


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
        if ($this->proceeding === null || $this->proceeding->infosoudJson === null) {
            $this->fetchFromInfosoud($ref);
        }

        if ($this->proceeding === null) {
            $this->error('Řízení se nepodařilo najít (v systému ani na infoSoudu).');
        }

        // The Spisovka used from here on is the authoritative one from the DB.
        $this->spisovka = $this->spisovkaFactory->fromCaseFile($this->proceeding);
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
        $this->spisovka = $this->spisovkaFactory->fromCaseFile($this->proceeding);

        $event = $this->events->getById($id);
        if ($event === null || $event->caseFileId !== $this->proceeding->id) {
            $this->error('Neznámá událost.');
        }
        $this->event = $event;

        if ($event->detailFetchedAt === null) {
            $this->fetchEventDetail();
        }
    }


    /** Manual one-off refresh (per-case cooldown applies). */
    public function handleRefresh(): void
    {
        $at = $this->proceeding?->infosoudAt;
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
            || $event->caseFileId !== $this->proceeding->id) {
            $this->error('Neznámá událost.');
        }
        $this->event = $event;
        if ($event->detailFetchedAt === null) {
            $this->fetchEventDetail();
        }
        $this->redirect('this');
    }


    /** Manual refresh of one event detail (per-event cooldown applies). */
    public function handleRefreshEvent(): void
    {
        $at = $this->event?->detailFetchedAt;
        if ($at !== null && $at > new \DateTimeImmutable(self::RefreshCooldown)) {
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

        $infosoud = $proceeding->infosoudJson !== null
            ? Json::decode($proceeding->infosoudJson, forceArrays: true)
            : null;
        $isir = $proceeding->isirJson !== null
            ? Json::decode($proceeding->isirJson, forceArrays: true)
            : null;

        $attributes = $this->caseSummary->attributesOf($proceeding);

        // Display form of the file number comes from the codelist-backed Spisovka.
        $this->template->court = $this->court;
        $this->template->spisovkaLabel = $this->spisovka->format();
        $this->template->caseSlug = $this->spisovka->toSlug();
        $this->template->infosoudAt = $proceeding->infosoudAt;
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
        $this->template->isStale = $proceeding->infosoudAt !== null
            && $proceeding->infosoudAt < new \DateTimeImmutable(self::StaleThreshold);
        // The header only needs to know whether the case is bookmarked and
        // under which custom name - the entity itself stays in the presenter.
        $favorite = $this->currentFavorite();
        $this->template->isFavorite = $favorite !== null;
        $this->template->favoriteName = $favorite?->name;
    }


    /** Level of the case's court (the URL court). */
    private function courtLevel(): CourtLevel
    {
        return $this->court->level;
    }


    /** The logged-in user's favorite of the current case, if any. */
    private function currentFavorite(): ?Favorite
    {
        if (!$this->getUser()->isLoggedIn() || $this->proceeding === null) {
            return null;
        }
        return $this->favorites->getByUserAndProceeding((int) $this->getUser()->getId(), $this->proceeding->id);
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
            $favorite = new Favorite;
            $favorite->userId = (int) $this->getUser()->getId();
            $favorite->proceedingId = $this->proceeding->id;
            $favorite->name = $data->name;
            $this->favorites->add($favorite);
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
        $ownerCourt = $event->refCourtKod !== null
            ? $this->courts->getByKod($event->refCourtKod)
            : $this->court;
        $ownerLevel = $ownerCourt !== null ? $ownerCourt->level : $this->court->level;
        $code = $event->eventCode;

        $detail = $event->detailJson !== null
            ? Json::decode($event->detailJson, forceArrays: true)
            : null;

        $owner = null;
        if ($event->refRegistryNorm !== null) {
            $ownerSpisovka = $this->spisovkaFactory->fromEventRef($event);
            $owner = $this->caseChip($ownerCourt, $ownerSpisovka);
        }

        $this->template->eventLabel = InfosoudEventType::label($code, $ownerLevel);
        $this->template->eventDate = $event->eventDate;
        $this->template->eventCancelled = $event->cancelled;
        $this->template->eventFetchedAt = $event->detailFetchedAt;
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
    private function hasUpstreamAddress(CaseFileEvent $event): bool
    {
        return $event->eventOrder !== null
            && ($event->refRegistryNorm === null || $event->refCourtKod !== null);
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
        if ($soud !== $court->slug || $znacka !== $ref->toSlug()) {
            $this->redirectPermanent(
                $action,
                ['soud' => $court->slug, 'znacka' => $ref->toSlug()] + $extraParams,
            );
        }

        return $ref;
    }


    private function loadProceeding(Spisovka $ref): ?CaseFile
    {
        return $this->proceedings->getByCase((string) $this->court->kod, $ref);
    }


    private function fetchFromInfosoud(Spisovka $ref): void
    {
        try {
            $stored = $this->sync->refreshFromInfosoud($this->court, $ref);
            if ($stored !== null) {
                $this->proceeding = $stored;
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
        if ($event->eventOrder === null) {
            return; // no upstream address for this record
        }

        // The case whose sequence owns the record: the case itself, or the
        // foreign case (appeals etc.) from the ref columns.
        if ($event->refRegistryNorm === null) {
            $court = $this->court;
            $spisovka = $this->spisovka;
        } else {
            $court = $event->refCourtKod !== null ? $this->courts->getByKod($event->refCourtKod) : null;
            if ($court === null) {
                return; // unknown owner court - keep the thin row
            }
            $spisovka = $this->spisovkaFactory->fromEventRef($event);
        }

        try {
            $detail = $this->client->fetchEventDetail(
                $court,
                $spisovka,
                $event->eventCode,
                $event->eventOrder,
                upstreamId: $event->upstreamId,
            );
        } catch (InfosoudApiException) {
            $this->flashMessage('InfoSoud je momentálně nedostupný — detail události se nepodařilo načíst.', 'error');
            return;
        }

        $now = new \DateTimeImmutable;
        if ($detail === null) {
            // Upstream has no detail for this record; remember that so the
            // page does not retry on every view.
            $missing = new CaseFileEvent;
            $missing->detailJson = null;
            $missing->detailFetchedAt = $now;
            $this->events->update($event->id, $missing);
            $this->event = $this->events->getById($event->id);
            return;
        }

        $rowDate = $event->eventDate?->format('Y-m-d');
        $detailDate = \DateTimeImmutable::createFromFormat('!d.m.Y', (string) ($detail['datumUdalost'] ?? ''));
        $typeMatches = (string) ($detail['typUdalosti'] ?? '') === $event->eventCode;
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

        $fetched = new CaseFileEvent;
        $fetched->detailJson = Json::encode($detail);
        $fetched->detailFetchedAt = $now;
        $this->events->update($event->id, $fetched);
        $this->event = $this->events->getById($event->id);
    }


    /** @return list<array<string, mixed>> */
    private function buildEventsView(): array
    {
        assert($this->proceeding !== null);
        $level = $this->courtLevel();
        $today = new \DateTimeImmutable('today');
        $items = [];
        foreach ($this->events->findByCaseFile($this->proceeding->id) as $event) {
            $foreign = null;
            if ($event->refRegistryNorm !== null) {
                $court = $event->refCourtKod !== null ? $this->courts->getByKod($event->refCourtKod) : null;
                $spisovka = $this->spisovkaFactory->fromEventRef($event);
                $foreign = $this->caseChip($court, $spisovka);
            }

            // Interim hearing info parsed from the NAR_JED detail (hearings
            // will later be scraped separately, see docs/infosoud-api.md).
            $isHearing = $event->eventCode === 'NAR_JED';
            $cancelled = $event->cancelled;
            $hearing = null;
            if ($isHearing && !$cancelled && $event->detailJson !== null) {
                $detail = Json::decode($event->detailJson, forceArrays: true);
                $hearing = is_array($detail) ? InfosoudHearing::fromEventDetail($detail) : null;
            }

            $items[] = [
                'id' => $event->id,
                'date' => $event->eventDate,
                'label' => InfosoudEventType::label($event->eventCode, $level),
                'cancelled' => $cancelled,
                'hasDetail' => $event->detailFetchedAt !== null,
                'foreign' => $foreign,
                'hearing' => $hearing,
                'hearingFetchable' => $isHearing && !$cancelled && $event->detailFetchedAt === null
                    && $this->hasUpstreamAddress($event),
                'upcoming' => $isHearing && !$cancelled
                    && $event->eventDate !== null && $event->eventDate >= $today,
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
                $stored = $court !== null
                    ? $this->proceedings->getByCase((string) $court->kod, $spisovka)
                    : null;
                $items[$key] = [
                    'relations' => [],
                    'cached' => $stored !== null,
                    // enrichment from what we already hold, never an upstream request
                    'subject' => $stored !== null ? $this->caseSummary->subjectOf($stored) : null,
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
            $p->courtKod, $p->registryNorm,
            $this->courtLevel() === CourtLevel::Supreme ? null : $p->senate,
            $p->bcNumber, $p->year,
        ];
        foreach ($this->relations->findBySrc(...$identity) as $rel) {
            $push(
                $rel->dstCourtKod,
                $rel->dstRegistryNorm,
                $rel->dstSenate,
                $rel->dstBcNumber,
                $rel->dstYear,
                $types[$rel->relationType]->label ?? $rel->relationType,
            );
        }
        foreach ($this->relations->findByDst(...$identity) as $rel) {
            $push(
                $rel->srcCourtKod,
                $rel->srcRegistryNorm,
                $rel->srcSenate,
                $rel->srcBcNumber,
                $rel->srcYear,
                $types[$rel->relationType]->labelReverse ?? $rel->relationType,
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
    private function courtNamedIn(array $values, string $type): ?Court
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
    private function caseChip(?Court $court, Spisovka $spisovka): array
    {
        $isCourtCase = $this->isCourtRegistry($spisovka);
        return [
            'label' => $spisovka->format(),
            'courtSlug' => $court?->slug,
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
    private function resolveCaseReferences(array $parts, array $relatedCourts, ?Court $courtHint = null): ?array
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
    private function relatedCourtIndex(CaseFile $case): array
    {
        $identity = [
            $case->courtKod, $case->registryNorm,
            $this->courtLevel() === CourtLevel::Supreme ? null : $case->senate,
            $case->bcNumber, $case->year,
        ];
        $index = [];
        foreach ($this->relations->findBySrc(...$identity) as $rel) {
            $key = $rel->dstRegistryNorm . '|' . $rel->dstSenate . '|' . $rel->dstBcNumber . '|' . $rel->dstYear;
            $index[$key] ??= $rel->dstCourtKod;
        }
        foreach ($this->relations->findByDst(...$identity) as $rel) {
            $key = $rel->srcRegistryNorm . '|' . $rel->srcSenate . '|' . $rel->srcBcNumber . '|' . $rel->srcYear;
            $index[$key] ??= $rel->srcCourtKod;
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
    private function buildEventInfosoudUrl(CaseFileEvent $event): ?string
    {
        if ($event->eventOrder === null) {
            return null;
        }
        $owner = null;
        $ownerCourt = null;
        if ($event->refRegistryNorm !== null) {
            $ownerCourt = $event->refCourtKod !== null ? $this->courts->getByKod($event->refCourtKod) : null;
            if ($ownerCourt === null) {
                return null;
            }
            $owner = $this->spisovkaFactory->fromEventRef($event);
        }
        return $this->linkBuilder->eventDetailUrl(
            $this->spisovka,
            $this->court,
            $event->eventCode,
            $event->eventOrder,
            $owner,
            $ownerCourt,
            $event->upstreamId,
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
