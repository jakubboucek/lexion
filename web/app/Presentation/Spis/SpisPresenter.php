<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Favorite\Favorite;
use App\Model\Favorite\FavoriteRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\EventDetailOutcome;
use App\Model\Proceeding\EventDetailService;
use App\Model\Proceeding\CaseFile;
use App\Model\Proceeding\CaseFileEvent;
use App\Model\Proceeding\ProceedingEventRepository;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Proceeding\ProceedingSyncService;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaSlugParser;
use App\Presentation\Accessory\FavoriteControl;
use App\Presentation\Accessory\FavoriteControlFactory;
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
    /** Manual refresh is ignored when the cache is younger than this. */
    private const string RefreshCooldown = '-5 minutes';

    private Court $court;
    private Spisovka $spisovka;      // canonical, built from the DB row
    private ?CaseFile $proceeding = null;
    private ?CaseFileEvent $event = null;
    private ?Favorite $favorite = null; // memo of currentFavorite()


    public function __construct(
        private readonly CourtRepository $courts,
        private readonly ProceedingRepository $proceedings,
        private readonly ProceedingEventRepository $events,
        private readonly ProceedingSyncService $sync,
        private readonly EventDetailService $eventDetails,
        private readonly FavoriteRepository $favorites,
        private readonly FavoriteControlFactory $favoriteControls,
        private readonly SpisovkaSlugParser $slugParser,
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CaseViewFactory $views,
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
        $context = $this->context();
        $this->template->caseHeader = $this->views->header($context, $this->currentFavorite());
        [$events, $undated] = $this->views->timeline($context);
        $this->template->events = $events;
        $this->template->undatedEvents = $undated;
        $this->template->related = $this->views->related($context);
        $this->template->infosoudUrl = $this->linkBuilder->detailUrl($this->spisovka, $this->court);
    }


    /** The case this request is about; both actions have resolved it by now. */
    private function context(): CaseContext
    {
        assert($this->proceeding !== null); // both actions 404 otherwise
        return new CaseContext($this->proceeding, $this->court, $this->spisovka);
    }


    /** The logged-in user's favorite of the current case, if any. */
    private function currentFavorite(): ?Favorite
    {
        if (!$this->getUser()->isLoggedIn() || $this->proceeding === null) {
            return null;
        }
        // Read once per request: the header shows the custom name, the star
        // component needs the same row.
        return $this->favorite ??= $this->favorites
            ->getByUserAndProceeding((int) $this->getUser()->getId(), $this->proceeding->id);
    }


    /** Bookmark star of this case, with its two modals (see FavoriteControl). */
    protected function createComponentFavorite(): FavoriteControl
    {
        assert($this->proceeding !== null); // both actions 404 otherwise
        return $this->favoriteControls->create($this->proceeding, $this->currentFavorite());
    }


    public function renderUdalost(): void
    {
        $event = $this->event;
        assert($event !== null); // actionUdalost() 404s otherwise

        $context = $this->context();
        $this->template->caseHeader = $this->views->header($context, $this->currentFavorite());
        $this->template->event = $this->views->event($context, $event);
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
}
