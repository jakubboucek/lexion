<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\CaseFile\CaseFile;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileEventRepository;
use App\Model\CaseFile\CaseFileRepository;
use App\Model\CaseFile\CaseFileSyncService;
use App\Model\CaseFile\CaseLoadOutcome;
use App\Model\CaseFile\CaseLoadPolicy;
use App\Model\CaseFile\EventDetailOutcome;
use App\Model\CaseFile\EventDetailService;
use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Favorite\Favorite;
use App\Model\Favorite\FavoriteRepository;
use App\Model\Infosoud\InfosoudLinkBuilder;
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
 * Events and relations render from the projected tables (case_file_event /
 * case_file_relation, see docs/analyza-udalosti.md); the event detail page
 * addresses rows by their surrogate id and lazily fetches the upstream detail.
 */
final class SpisPresenter extends Nette\Application\UI\Presenter
{
    /** Manual refresh is ignored when the cache is younger than this. */
    private const string RefreshCooldown = '-5 minutes';

    private Court $court;
    private Spisovka $spisovka;      // canonical, built from the DB row
    private ?CaseFile $caseFile = null;
    private ?CaseFileEvent $event = null;
    private ?Favorite $favorite = null; // memo of currentFavorite()


    public function __construct(
        private readonly CourtRepository $courts,
        private readonly CaseFileRepository $caseFiles,
        private readonly CaseFileEventRepository $events,
        private readonly CaseFileSyncService $sync,
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

        // Cache-first: infosoud is asked only when we hold no infosoud data yet.
        $this->loadCase($ref, CaseLoadPolicy::InfosoudData);

        if ($this->caseFile === null) {
            throw new UserFacingError('Řízení se nepodařilo najít (v systému ani na infoSoudu).');
        }

        // The Spisovka used from here on is the authoritative one from the DB.
        $this->spisovka = $this->spisovkaFactory->fromCaseFile($this->caseFile);
    }


    public function actionUdalost(string $soud, string $znacka, int $id): void
    {
        $ref = $this->resolveCase($soud, $znacka, 'udalost', ['id' => $id]);

        // Event pages exist only for already-loaded cases; ids would not match
        // anything otherwise, so no upstream fetch here.
        $this->caseFile = $this->caseFiles->getByCase((string) $this->court->kod, $ref);
        if ($this->caseFile === null) {
            throw new UserFacingError('Řízení neevidujeme.');
        }
        $this->spisovka = $this->spisovkaFactory->fromCaseFile($this->caseFile);

        $this->event = $this->ownEvent($id);

        // A thin row fetches its detail on first view; an already fetched one
        // is a no-op inside the service.
        $this->fetchEventDetail();
    }


    /** Manual one-off refresh (per-case cooldown applies). */
    public function handleRefresh(): void
    {
        if ($this->isCoolingDown($this->caseFile?->infosoudAt)) {
            $this->flashMessage('Data byla aktualizována před chvílí, zkuste to později.');
        } else {
            $this->loadCase($this->spisovka, CaseLoadPolicy::Refresh);
        }
        $this->redirect('this');
    }


    /** Fetches one event's detail from the case timeline, staying on the timeline. */
    public function handleFetchEvent(int $id): void
    {
        $this->event = $this->ownEvent($id);
        $this->fetchEventDetail();
        $this->redirect('this');
    }


    /** Manual refresh of one event detail (per-event cooldown applies). */
    public function handleRefreshEvent(): void
    {
        if ($this->isCoolingDown($this->event?->detailFetchedAt)) {
            $this->flashMessage('Detail události byl aktualizován před chvílí, zkuste to později.');
        } else {
            $this->fetchEventDetail(refetch: true);
        }
        $this->redirect('this');
    }


    /**
     * The event of the current case, or a 404 - an id from another case must
     * not open here even though the row exists.
     */
    private function ownEvent(int $id): CaseFileEvent
    {
        $event = $this->events->getById($id);
        if ($event === null || $this->caseFile === null
            || $event->caseFileId !== $this->caseFile->id) {
            throw new UserFacingError('Neznámá událost.');
        }
        return $event;
    }


    /** Whether a manual refresh is still within the cooldown of the last fetch. */
    private function isCoolingDown(?\DateTimeImmutable $fetchedAt): bool
    {
        return $fetchedAt !== null && $fetchedAt > new \DateTimeImmutable(self::RefreshCooldown);
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
        assert($this->caseFile !== null); // both actions 404 otherwise
        return new CaseContext($this->caseFile, $this->court, $this->spisovka);
    }


    /** The logged-in user's favorite of the current case, if any. */
    private function currentFavorite(): ?Favorite
    {
        if (!$this->getUser()->isLoggedIn() || $this->caseFile === null) {
            return null;
        }
        // Read once per request: the header shows the custom name, the star
        // component needs the same row.
        return $this->favorite ??= $this->favorites
            ->getByUserAndCaseFile((int) $this->getUser()->getId(), $this->caseFile->id);
    }


    /** Bookmark star of this case, with its two modals (see FavoriteControl). */
    protected function createComponentFavorite(): FavoriteControl
    {
        assert($this->caseFile !== null); // both actions 404 otherwise
        return $this->favoriteControls->create($this->caseFile, $this->currentFavorite());
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


    /**
     * Loads the case into $caseFile, going upstream only when needed, and
     * says out loud what the visitor is looking at when that did not work out.
     */
    private function loadCase(Spisovka $ref, CaseLoadPolicy $policy): void
    {
        $result = $this->sync->ensureLoaded($this->court, $ref, $policy);
        $this->caseFile = $result->case;

        if ($result->outcome === CaseLoadOutcome::NotFound && $result->case !== null) {
            $this->flashMessage('Řízení se na infoSoudu nepodařilo najít; zobrazuji informace z ostatních zdrojů.', 'error');
        } elseif ($result->outcome === CaseLoadOutcome::Rejected) {
            // Refused, not broken: infoSoud will not answer for this mark at
            // this court however often we ask, so never suggest coming back.
            $refusal = 'InfoSoud tuto spisovou značku u tohoto soudu nevyhledává.';
            if ($result->case === null) {
                throw new UserFacingError($refusal, Nette\Http\IResponse::S404_NotFound);
            }
            $this->flashMessage($refusal . ' — zobrazuji poslední známý stav.', 'error');
        } elseif ($result->outcome === CaseLoadOutcome::Unavailable) {
            if ($result->case === null) {
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
