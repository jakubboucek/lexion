<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\RegistryRepository;
use App\Model\Codelist\RelationTypeRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Infosoud\InfosoudEventType;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Proceeding\ProceedingEventRepository;
use App\Model\Proceeding\ProceedingRelationRepository;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Proceeding\ProceedingSyncService;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaSlugParser;
use Nette;
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
        private readonly InfosoudClient $client,
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

        // Display form of the file number comes from the codelist-backed Spisovka.
        $this->template->court = $this->court;
        $this->template->spisovkaLabel = $this->spisovka->format();
        $this->template->caseSlug = $this->spisovka->toSlug();
        $this->template->proceeding = $proceeding;
        $this->template->infosoud = $infosoud;
        $this->template->isir = $isir;
        $this->template->subject = ($attributes['PREDM_RIZ'] ?? '-') !== '-' ? $attributes['PREDM_RIZ'] : null;
        $this->template->nsAttributes = array_intersect_key(
            $attributes,
            array_flip(['SENAT', 'SLOZENI_SENATU', 'ODVOL_SOUD', 'PR_VEC_NS']),
        );
        $this->template->events = $this->buildEventsView();
        $this->template->related = $this->buildRelatedView();
        $this->template->infosoudUrl = $this->linkBuilder->detailUrl($this->spisovka, $this->court);
        $this->template->isStale = $proceeding->infosoud_at !== null
            && $proceeding->infosoud_at < new \DateTimeImmutable(self::StaleThreshold);
    }


    public function renderUdalost(): void
    {
        $event = $this->event;
        assert($event !== null); // actionUdalost() 404s otherwise

        // Labels follow the flavor of the court owning the record (a foreign
        // NS event in a KS timeline uses the NS wording).
        $ownerCourt = $event->ref_court_kod !== null
            ? $this->courts->getByKod((string) $event->ref_court_kod)
            : $this->court;
        $ownerLevel = $ownerCourt !== null ? (string) $ownerCourt->level : (string) $this->court->level;
        $supreme = $ownerLevel === 'ns';
        $code = (string) $event->event_code;

        $detail = $event->detail_json !== null
            ? Json::decode((string) $event->detail_json, forceArrays: true)
            : null;

        $owner = null;
        if ($event->ref_registry_norm !== null) {
            $ownerSpisovka = $this->refSpisovka($event);
            $owner = [
                'label' => $ownerSpisovka->format(),
                'courtSlug' => $ownerCourt !== null ? (string) $ownerCourt->slug : null,
                'courtName' => $ownerCourt?->name,
                'slug' => $ownerSpisovka->toSlug(),
                'linkable' => $ownerCourt !== null && $this->isCourtRegistry($ownerSpisovka),
            ];
        }

        $this->template->court = $this->court;
        $this->template->spisovkaLabel = $this->spisovka->format();
        $this->template->caseSlug = $this->spisovka->toSlug();
        $this->template->event = $event;
        $this->template->eventLabel = InfosoudEventType::label($code, $supreme);
        $this->template->eventDescription = InfosoudEventType::description($code, $supreme);
        $this->template->owner = $owner;
        $this->template->attributes = $this->buildAttributesView($detail, $ownerLevel);
        $this->template->navazneVeci = $this->buildNavazneView($detail);
        $this->template->navazneFirst = $code === 'DOVOL_RIZ'; // SPA renders them above attributes for DOVOL_RIZ
        $this->template->infosoudUrl = $this->buildEventInfosoudUrl($event);
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
        return $this->proceedings->getByCase(
            (string) $this->court->kod,
            $ref->registryNorm(),
            $ref->senate,
            $ref->number,
            $ref->year,
        );
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
            $spisovka = $this->refSpisovka($event);
        }

        try {
            $detail = $this->client->fetchEventDetail(
                $court,
                $spisovka->senate,
                $spisovka->registryNorm(),
                $spisovka->number,
                $spisovka->year,
                (string) $event->event_code,
                (int) $event->event_order,
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
        $supreme = $this->court->level === 'ns';
        $items = [];
        foreach ($this->events->findByProceeding((int) $this->proceeding->id) as $row) {
            $foreign = null;
            if ($row->ref_registry_norm !== null) {
                $court = $row->ref_court_kod !== null ? $this->courts->getByKod((string) $row->ref_court_kod) : null;
                $spisovka = $this->refSpisovka($row);
                $foreign = [
                    'label' => $spisovka->format(),
                    'courtSlug' => $court !== null ? (string) $court->slug : null,
                    'courtName' => $court?->name,
                    'slug' => $spisovka->toSlug(),
                    'linkable' => $court !== null && $this->isCourtRegistry($spisovka),
                ];
            }
            $items[] = [
                'id' => (int) $row->id,
                'date' => $row->event_date,
                'label' => InfosoudEventType::label((string) $row->event_code, $supreme),
                'cancelled' => (bool) $row->cancelled,
                'hasDetail' => $row->detail_fetched_at !== null,
                'foreign' => $foreign,
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
                $cached = $court !== null && $this->proceedings->getByCase(
                    (string) $court->kod,
                    $spisovka->registryNorm(),
                    $senate,
                    $bcNumber,
                    $year,
                ) !== null;
                $items[$key] = [
                    'label' => $spisovka->format(),
                    'courtSlug' => $court !== null ? (string) $court->slug : null,
                    'courtName' => $court?->name,
                    'slug' => $spisovka->toSlug(),
                    'relations' => [],
                    'cached' => $cached,
                    'linkable' => $court !== null && $this->isCourtRegistry($spisovka),
                ];
            }
            if (!in_array($relationLabel, $items[$key]['relations'], true)) {
                $items[$key]['relations'][] = $relationLabel;
            }
        };

        $identity = [(string) $p->court_kod, (string) $p->registry_norm, (int) $p->senate, (int) $p->bc_number, (int) $p->year];
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
     * @return list<array{label: string, value: ?string}>
     */
    private function buildAttributesView(?array $detail, string $ownerLevel): array
    {
        $items = [];
        foreach ($detail['atributy'] ?? [] as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $type = (string) ($attribute['typ'] ?? '');
            $value = trim((string) ($attribute['hodnota'] ?? ''));
            if ($type === '') {
                continue;
            }
            if ($type === InfosoudEventAttribute::FlagAttribute) {
                if ($value === InfosoudEventAttribute::FlagTrue) {
                    $items[] = ['label' => InfosoudEventAttribute::label($type, $ownerLevel), 'value' => null];
                }
                continue;
            }
            if ($value === '' || $value === '-') {
                continue; // not stated
            }
            $items[] = [
                'label' => InfosoudEventAttribute::label($type, $ownerLevel),
                'value' => implode(', ', array_map(trim(...), explode('|', $value))),
            ];
        }
        return $items;
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
                (int) ($ref['rocnik'] ?? 0),
            );
            $cached = $court !== null && $this->proceedings->getByCase(
                (string) $court->kod,
                $spisovka->registryNorm(),
                $spisovka->senate,
                $spisovka->number,
                $spisovka->year,
            ) !== null;
            $items[] = [
                'typeLabel' => InfosoudEventAttribute::label((string) ($ref['typ'] ?? ''), (string) $this->court->level),
                'label' => $spisovka->format(),
                'courtSlug' => $court !== null ? (string) $court->slug : null,
                'courtName' => $court?->name,
                'slug' => $spisovka->toSlug(),
                'cached' => $cached,
                'linkable' => $court !== null && $this->isCourtRegistry($spisovka),
            ];
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
            $owner = $this->refSpisovka($event);
        }
        return $this->linkBuilder->eventDetailUrl(
            $this->spisovka,
            $this->court,
            (string) $event->event_code,
            (int) $event->event_order,
            $owner,
            $ownerCourt,
        );
    }


    /** Spisovka of the foreign owner case from the ref columns of an event row. */
    private function refSpisovka(ActiveRow $event): Spisovka
    {
        return $this->spisovkaFactory->fromCase(
            (int) $event->ref_senate,
            (string) $event->ref_registry_norm,
            (int) $event->ref_bc_number,
            (int) $event->ref_year,
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
