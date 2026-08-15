<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Favorite\Favorite;
use App\Model\Favorite\FavoriteRepository;
use App\Model\Codelist\RelationTypeRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Codelist\CourtLevel;
use App\Model\Infosoud\InfosoudCaseOverview;
use App\Model\Infosoud\InfosoudCollegium;
use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Infosoud\InfosoudEventType;
use App\Model\Infosoud\InfosoudHearing;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\CaseSummaryService;
use App\Model\Proceeding\EventDetailOutcome;
use App\Model\Proceeding\EventDetailService;
use App\Model\Proceeding\CaseFile;
use App\Model\Proceeding\CaseFileEvent;
use App\Model\Proceeding\CaseFileRelation;
use App\Model\Proceeding\ProceedingEventRepository;
use App\Model\Proceeding\ProceedingRelationRepository;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Proceeding\ProceedingSyncService;
use App\Model\Proceeding\StoredJson;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaSlugParser;
use App\Presentation\Accessory\CaseChipFactory;
use App\Presentation\Error\UserFacingError;
use Nette;
use Nette\Application\UI\Form;


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
    /** @var array{list<CaseFileRelation>, list<CaseFileRelation>}|null both directions, fetched once */
    private ?array $relationRows = null;


    public function __construct(
        private readonly CourtRepository $courts,
        private readonly RelationTypeRepository $relationTypes,
        private readonly ProceedingRepository $proceedings,
        private readonly ProceedingEventRepository $events,
        private readonly ProceedingRelationRepository $relations,
        private readonly ProceedingSyncService $sync,
        private readonly EventDetailService $eventDetails,
        private readonly CaseSummaryService $caseSummary,
        private readonly FavoriteRepository $favorites,
        private readonly SpisovkaSlugParser $slugParser,
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CaseChipFactory $chips,
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
            throw new UserFacingError('Řízení se nepodařilo najít (v systému ani na infoSoudu).');
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
            throw new UserFacingError('Řízení neevidujeme.');
        }
        $this->spisovka = $this->spisovkaFactory->fromCaseFile($this->proceeding);

        $event = $this->events->getById($id);
        if ($event === null || $event->caseFileId !== $this->proceeding->id) {
            throw new UserFacingError('Neznámá událost.');
        }
        $this->event = $event;

        // A thin row fetches its detail on first view; an already fetched one
        // is a no-op inside the service.
        $this->fetchEventDetail();
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
            throw new UserFacingError('Neznámá událost.');
        }
        $this->event = $event;
        $this->fetchEventDetail();
        $this->redirect('this');
    }


    /** Manual refresh of one event detail (per-event cooldown applies). */
    public function handleRefreshEvent(): void
    {
        $at = $this->event?->detailFetchedAt;
        if ($at !== null && $at > new \DateTimeImmutable(self::RefreshCooldown)) {
            $this->flashMessage('Detail události byl aktualizován před chvílí, zkuste to později.');
        } else {
            $this->fetchEventDetail(refetch: true);
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


    /** Builds the shared case header view (see @case-header.latte). */
    private function assignCaseHeader(): void
    {
        $proceeding = $this->proceeding;
        assert($proceeding !== null); // both actions 404 otherwise

        $attributes = $this->caseSummary->attributesOf($proceeding);

        // Supreme Court extras, already in display form - a multi-value
        // attribute (SLOZENI_SENATU lists judges separated by "|") is joined
        // here, never in the template.
        $nsAttributes = array_map(
            InfosoudEventAttribute::formatMulti(...),
            array_filter(array_intersect_key(
                $attributes,
                array_flip(['SENAT', 'SLOZENI_SENATU', 'ODVOL_SOUD', 'PR_VEC_NS']),
            ), static fn(?string $value): bool => $value !== null),
        );
        // The file number under review renders as the usual chip; its court is
        // the one named in ODVOL_SOUD (see buildAttributesView).
        $challenged = ($nsAttributes['PR_VEC_NS'] ?? null) !== null
            ? $this->chips->references(
                [$nsAttributes['PR_VEC_NS']],
                $this->relatedCourtIndex(),
                $this->chips->courtNamedIn($nsAttributes, 'PR_VEC_NS'),
            )
            : null;
        $favorite = $this->currentFavorite();

        $this->template->caseHeader = new CaseHeaderView(
            court: $this->court,
            // Display form of the file number comes from the codelist-backed Spisovka.
            spisovkaLabel: $this->spisovka->format(),
            caseSlug: $this->spisovka->toSlug(),
            infosoudAt: $proceeding->infosoudAt,
            // Typed view of the raw overview JSON; the upstream shape is the
            // struct's business (ST-3), an empty column yields an empty instance.
            overview: InfosoudCaseOverview::fromJson($proceeding->infosoudJson),
            subject: $this->caseSummary->subjectFrom($attributes),
            // Supreme Court cases carry no state; the SPA shows the collegium there.
            collegium: $this->courtLevel() === CourtLevel::Supreme
                ? InfosoudCollegium::forRegistry($this->spisovka->registryNorm())
                : null,
            nsAttributes: $nsAttributes,
            nsChallenged: $challenged[0] ?? null,
            isStale: $proceeding->infosoudAt !== null
                && $proceeding->infosoudAt < new \DateTimeImmutable(self::StaleThreshold),
            // The header only needs to know whether the case is bookmarked and
            // under which custom name - the entity itself stays in the presenter.
            isFavorite: $favorite !== null,
            favoriteName: $favorite?->name,
        );
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
            throw new UserFacingError('Přihlášení je vyžadováno.', Nette\Http\IResponse::S403_Forbidden);
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
            throw new UserFacingError('Přihlášení je vyžadováno.', Nette\Http\IResponse::S403_Forbidden);
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

        // A thin row (no detail yet) reads as an empty detail; damaged stored
        // JSON raises instead of quietly rendering an empty page.
        $detail = StoredJson::decode($event->detailJson, "event #{$event->id} (detail_json)");

        $owner = null;
        if ($event->refRegistryNorm !== null) {
            $ownerSpisovka = $this->spisovkaFactory->fromEventRef($event);
            $owner = $this->chips->chip($ownerCourt, $ownerSpisovka);
        }

        $this->template->eventLabel = InfosoudEventType::label($code, $ownerLevel);
        $this->template->eventDate = $event->eventDate;
        $this->template->eventCancelled = $event->cancelled;
        $this->template->eventFetchedAt = $event->detailFetchedAt;
        $this->template->eventDescription = InfosoudEventType::description($code, $ownerLevel);
        $this->template->owner = $owner;
        assert($this->proceeding !== null); // actionUdalost() 404s otherwise
        $this->template->attributes = $this->buildAttributesView($detail, $ownerLevel, $this->relatedCourtIndex());
        $this->template->navazneVeci = $this->buildNavazneView($detail);
        $this->template->navazneFirst = $code === 'DOVOL_RIZ'; // SPA renders them above attributes for DOVOL_RIZ
        $this->template->eventInfosoudUrl = $this->buildEventInfosoudUrl($event);
        $this->template->eventFetchable = $this->eventDetails->isAddressable($event);
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
            throw new UserFacingError('Neznámý soud.');
        }
        $this->court = $court;

        // Parse the slug locally, only as a lookup key.
        try {
            $ref = $this->slugParser->parse($znacka);
        } catch (SpisovkaParseException $e) {
            throw new UserFacingError('Neplatná spisová značka: ' . $e->getMessage());
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
                throw new UserFacingError('InfoSoud je momentálně nedostupný, zkuste to prosím později.', Nette\Http\IResponse::S503_ServiceUnavailable);
            }
            $this->flashMessage('InfoSoud je momentálně nedostupný — zobrazuji poslední známý stav.', 'error');
        }
    }


    /**
     * Lazily fetches the upstream event detail through EventDetailService and
     * translates its outcome into what the visitor sees.
     */
    private function fetchEventDetail(bool $refetch = false): void
    {
        $event = $this->event;
        assert($event !== null);

        $result = $this->eventDetails->fetch($event, $this->court, $this->spisovka, $refetch);
        $this->event = $result->event;

        if ($result->outcome === EventDetailOutcome::Unavailable) {
            $this->flashMessage('InfoSoud je momentálně nedostupný — detail události se nepodařilo načíst.', 'error');
        } elseif ($result->outcome === EventDetailOutcome::IntegrityBroken) {
            // The projection has to be rebuilt before this record can be
            // addressed again, and only a case refresh does that.
            $this->flashMessage(
                'U tohoto spisu jsme zjistili narušení integrity dat (události se na infoSoudu přečíslovaly). '
                . 'Proveďte prosím aktualizaci spisu — odkazy na události se poté obnoví.',
                'error',
            );
            $this->redirect('detail', ['soud' => (string) $this->court->slug, 'znacka' => $this->spisovka->toSlug()]);
        }
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
                $foreign = $this->chips->chip($court, $spisovka);
            }

            // Interim hearing info parsed from the NAR_JED detail (hearings
            // will later be scraped separately, see docs/infosoud-api.md).
            $isHearing = $event->eventCode === 'NAR_JED';
            $cancelled = $event->cancelled;
            $hearing = null;
            if ($isHearing && !$cancelled && $event->detailJson !== null) {
                $detail = StoredJson::decode($event->detailJson, "event #{$event->id} (detail_json)");
                $hearing = InfosoudHearing::fromEventDetail($detail);
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
                    && $this->eventDetails->isAddressable($event),
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
        $types = $this->relationTypes->findAll();
        [$src, $dst] = $this->relationRows();

        // Collect the other side of every relation first; the rows we hold of
        // those cases (and their subjects) are then fetched for the whole page
        // at once instead of once per chip.
        $sides = [];
        $push = function (?string $courtKod, string $registryNorm, int $senate, int $bcNumber, int $year, string $relationLabel) use (&$sides): void {
            $key = ($courtKod ?? '') . '|' . $registryNorm . '|' . $senate . '|' . $bcNumber . '|' . $year;
            if (!isset($sides[$key])) {
                $sides[$key] = [
                    'courtKod' => $courtKod,
                    'spisovka' => $this->spisovkaFactory->fromCase($senate, $registryNorm, $bcNumber, $year),
                    'relations' => [],
                ];
            }
            if (!in_array($relationLabel, $sides[$key]['relations'], true)) {
                $sides[$key]['relations'][] = $relationLabel;
            }
        };

        foreach ($src as $rel) {
            $push(
                $rel->dstCourtKod,
                $rel->dstRegistryNorm,
                $rel->dstSenate,
                $rel->dstBcNumber,
                $rel->dstYear,
                $types[$rel->relationType]->label ?? $rel->relationType,
            );
        }
        foreach ($dst as $rel) {
            $push(
                $rel->srcCourtKod,
                $rel->srcRegistryNorm,
                $rel->srcSenate,
                $rel->srcBcNumber,
                $rel->srcYear,
                $types[$rel->relationType]->labelReverse ?? $rel->relationType,
            );
        }

        $stored = $this->chips->storedCases(array_values($sides));
        $subjects = $this->caseSummary->subjectsOf(array_values($stored));

        $items = [];
        foreach ($sides as $side) {
            $court = $side['courtKod'] !== null ? $this->courts->getByKod($side['courtKod']) : null;
            $case = $court !== null
                ? $stored[CaseFile::keyOf((string) $court->kod, $side['spisovka'])] ?? null
                : null;
            $items[] = [
                'relations' => $side['relations'],
                'cached' => $case !== null,
                // enrichment from what we already hold, never an upstream request
                'subject' => $case !== null ? $subjects[$case->id] ?? null : null,
            ] + $this->chips->chip($court, $side['spisovka']);
        }
        return $items;
    }


    /**
     * Relations of the case in both directions, fetched once per request - the
     * timeline view and the related-court index both need them.
     *
     * @return array{list<CaseFileRelation>, list<CaseFileRelation>} src side, dst side
     */
    private function relationRows(): array
    {
        if ($this->relationRows !== null) {
            return $this->relationRows;
        }
        assert($this->proceeding !== null); // both actions 404 otherwise
        $p = $this->proceeding;
        // Senate null for the Supreme Court: other courts' projections record a
        // reference to a NS case with senate 0 instead of the real number, so
        // matching on it would hide every relation pointing at this case.
        $identity = [
            $p->courtKod, $p->registryNorm,
            $this->courtLevel() === CourtLevel::Supreme ? null : $p->senate,
            $p->bcNumber, $p->year,
        ];
        return $this->relationRows = [
            $this->relations->findBySrc(...$identity),
            $this->relations->findByDst(...$identity),
        ];
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
            $parts = InfosoudEventAttribute::splitMulti($value);
            $items[] = [
                'label' => InfosoudEventAttribute::label($type, $ownerLevel),
                'value' => implode(', ', $parts),
                'cases' => InfosoudEventAttribute::isCaseReference($type)
                    ? $this->chips->references($parts, $relatedCourts, $this->chips->courtNamedIn($values, $type))
                    : null,
            ];
        }
        return $items;
    }


    /**
     * Identity keys of every case this one is related to, mapped to the court
     * kod (null when the relation carries no court).
     *
     * @return array<string, ?string>
     */
    private function relatedCourtIndex(): array
    {
        [$src, $dst] = $this->relationRows();
        $index = [];
        foreach ($src as $rel) {
            $key = $rel->dstRegistryNorm . '|' . $rel->dstSenate . '|' . $rel->dstBcNumber . '|' . $rel->dstYear;
            $index[$key] ??= $rel->dstCourtKod;
        }
        foreach ($dst as $rel) {
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
        $references = [];
        foreach ($detail['navazneVeci'] ?? [] as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $registryNorm = mb_strtoupper((string) ($ref['druh'] ?? ''));
            $bcNumber = (int) ($ref['bcVec'] ?? 0);
            if ($registryNorm === '' || $bcNumber === 0) {
                continue;
            }
            $kod = (string) ($ref['organizace'] ?? '');
            $references[] = [
                'courtKod' => $kod !== '' ? $kod : null,
                'spisovka' => $this->spisovkaFactory->fromCase(
                    (int) ($ref['cislo'] ?? 0),
                    $registryNorm,
                    $bcNumber,
                    CaseYear::fromUpstream((int) ($ref['rocnik'] ?? 0)),
                ),
                'typ' => (string) ($ref['typ'] ?? ''),
            ];
        }

        $stored = $this->chips->storedCases($references);

        $items = [];
        foreach ($references as $reference) {
            $court = $reference['courtKod'] !== null ? $this->courts->getByKod($reference['courtKod']) : null;
            $cached = $court !== null
                && isset($stored[CaseFile::keyOf((string) $court->kod, $reference['spisovka'])]);
            $items[] = [
                'typeLabel' => InfosoudEventAttribute::label($reference['typ'], $this->courtLevel()),
                'cached' => $cached,
            ] + $this->chips->chip($court, $reference['spisovka']);
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
}
