<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\CaseFile\CaseFile;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileEventRepository;
use App\Model\CaseFile\CaseFileRelation;
use App\Model\CaseFile\CaseFileRelationRepository;
use App\Model\CaseFile\CaseSummaryService;
use App\Model\CaseFile\EventDetailService;
use App\Model\CaseFile\StoredJson;
use App\Model\Codelist\CourtLevel;
use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\RelationTypeRepository;
use App\Model\Favorite\Favorite;
use App\Model\Infosoud\InfosoudCaseOverview;
use App\Model\Infosoud\InfosoudCollegium;
use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Infosoud\InfosoudEventType;
use App\Model\Infosoud\InfosoudHearing;
use App\Model\Infosoud\InfosoudLinkBuilder;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\SpisovkaFactory;
use App\Presentation\Accessory\CaseChipFactory;


/**
 * Builds the view models of the case pages: the shared header, the timeline,
 * the related-cases table and the event page (tech-debt ST-1 step 3). The
 * presenter is left with the request - resolving the case, fetching, signals -
 * and hands the rendering decisions here.
 *
 * Not a Nette component and deliberately stateless towards the request: every
 * method takes the CaseContext it works on. The only state is a per-case memo
 * of the relation rows, which two of the views need.
 */
final class CaseViewFactory
{
    /** Cache older than this shows the stale-data warning. */
    private const string StaleThreshold = '-1 month';

    /** @var array{list<CaseFileRelation>, list<CaseFileRelation>}|null */
    private ?array $relations = null;
    private ?int $relationsOf = null;


    public function __construct(
        private readonly CourtRepository $courts,
        private readonly RelationTypeRepository $relationTypes,
        private readonly CaseFileEventRepository $events,
        private readonly CaseFileRelationRepository $relationRepository,
        private readonly CaseSummaryService $caseSummary,
        private readonly EventDetailService $eventDetails,
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly InfosoudLinkBuilder $linkBuilder,
        private readonly CaseChipFactory $chips,
    ) {
    }


    /** View of the shared case header (see @case-header.latte). */
    public function header(CaseContext $context, ?Favorite $favorite): CaseHeaderView
    {
        $case = $context->case;
        $attributes = $this->caseSummary->attributesOf($case);

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
        // the one named in ODVOL_SOUD (see attributes()).
        $challenged = ($nsAttributes['PR_VEC_NS'] ?? null) !== null
            ? $this->chips->references(
                [$nsAttributes['PR_VEC_NS']],
                $this->relatedCourtIndex($context),
                $this->chips->courtNamedIn($nsAttributes, 'PR_VEC_NS'),
            )
            : null;

        return new CaseHeaderView(
            court: $context->court,
            // Display form of the file number comes from the codelist-backed Spisovka.
            spisovkaLabel: $context->spisovka->format(),
            caseSlug: $context->spisovka->toSlug(),
            infosoudAt: $case->infosoudAt,
            // Typed view of the raw overview JSON; the upstream shape is the
            // struct's business (ST-3), an empty column yields an empty instance.
            overview: InfosoudCaseOverview::fromJson($case->infosoudJson),
            subject: $this->caseSummary->subjectFrom($attributes),
            // Supreme Court cases carry no state; the SPA shows the collegium there.
            collegium: $context->court->level === CourtLevel::Supreme
                ? InfosoudCollegium::forRegistry($context->spisovka->registryNorm())
                : null,
            nsAttributes: $nsAttributes,
            nsChallenged: $challenged[0] ?? null,
            isStale: $case->infosoudAt !== null
                && $case->infosoudAt < new \DateTimeImmutable(self::StaleThreshold),
            // The header only needs to know whether the case is bookmarked and
            // under which custom name - the entity itself stays in the presenter.
            isFavorite: $favorite !== null,
            favoriteName: $favorite?->name,
        );
    }


    /**
     * Timeline rows, split into the ones the table can place and the ones it
     * cannot: records with no date (e.g. the NS "Poznámka o procesních
     * opatřeních") get their own box instead of floating to the top.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>} dated, undated
     */
    public function timeline(CaseContext $context): array
    {
        $level = $context->court->level;
        $today = new \DateTimeImmutable('today');
        $rows = $this->events->findByCaseFile($context->case->id);

        // Terms of a multi-term hearing are rows of their own pointing at the
        // aggregating record by (code, parentEventOrder, owner) - resolve
        // both directions once: the term's link target and the aggregate's
        // multi-term flag.
        $records = [];
        foreach ($rows as $event) {
            if ($event->eventOrder !== null && $event->parentEventOrder === null) {
                $records[self::recordKey($event, $event->eventOrder)] = $event;
            }
        }
        $aggregates = [];
        foreach ($rows as $event) {
            if ($event->parentEventOrder !== null) {
                $parent = $records[self::recordKey($event, $event->parentEventOrder)] ?? null;
                if ($parent !== null) {
                    $aggregates[$parent->id] = true;
                }
            }
        }

        $items = [];
        foreach ($rows as $event) {
            $foreign = null;
            if ($event->refRegistryNorm !== null) {
                $court = $event->refCourtKod !== null ? $this->courts->getByKod($event->refCourtKod) : null;
                $foreign = $this->chips->chip($court, $this->spisovkaFactory->fromEventRef($event));
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

            $parent = $event->parentEventOrder !== null
                ? $records[self::recordKey($event, $event->parentEventOrder)] ?? null
                : null;

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
                // Multi-term hearing block: the flag marks the aggregate and
                // every materialized term; a term also links its aggregate.
                'multiTerm' => isset($aggregates[$event->id]) || $event->parentEventOrder !== null,
                'partOf' => $event->parentEventOrder !== null
                    ? ['id' => $parent?->id, 'date' => $parent?->eventDate]
                    : null,
            ];
        }

        return [
            array_values(array_filter($items, static fn(array $e): bool => $e['date'] !== null)),
            array_values(array_filter($items, static fn(array $e): bool => $e['date'] === null)),
        ];
    }


    /**
     * Relations of the case from both directions of the N:M table, grouped by
     * the other side's identity; the direction picks label vs. label_reverse.
     *
     * @return list<array<string, mixed>>
     */
    public function related(CaseContext $context): array
    {
        $types = $this->relationTypes->findAll();
        [$src, $dst] = $this->relationRows($context);

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


    /** View of the event page below the header. */
    public function event(CaseContext $context, CaseFileEvent $event): EventView
    {
        // Labels follow the flavor of the court owning the record (a foreign
        // NS event in a KS timeline uses the NS wording).
        $ownerCourt = $event->refCourtKod !== null
            ? $this->courts->getByKod($event->refCourtKod)
            : $context->court;
        $ownerLevel = $ownerCourt !== null ? $ownerCourt->level : $context->court->level;

        // A thin row (no detail yet) reads as an empty detail; damaged stored
        // JSON raises instead of quietly rendering an empty page.
        $detail = StoredJson::decode($event->detailJson, "event #{$event->id} (detail_json)");

        [$partOf, $terms] = $this->hearingTerms($event);

        return new EventView(
            label: InfosoudEventType::label($event->eventCode, $ownerLevel),
            description: InfosoudEventType::description($event->eventCode, $ownerLevel),
            date: $event->eventDate,
            cancelled: $event->cancelled,
            fetchedAt: $event->detailFetchedAt,
            owner: $event->refRegistryNorm !== null
                ? $this->chips->chip($ownerCourt, $this->spisovkaFactory->fromEventRef($event))
                : null,
            attributes: $this->attributes($context, $detail, $ownerLevel),
            navazneVeci: $this->navazneVeci($context, $detail),
            // infosoud renders them above the attributes for DOVOL_RIZ
            navazneFirst: $event->eventCode === 'DOVOL_RIZ',
            infosoudUrl: $this->eventInfosoudUrl($context, $event),
            fetchable: $this->eventDetails->isAddressable($event),
            partOf: $partOf,
            terms: $terms,
        );
    }


    /**
     * Multi-term hearing block of the record: the aggregate a materialized
     * term is listed under, and the terms listed under this record (see
     * CaseFileEvent::$parentEventOrder). One indexed query per event page.
     *
     * @return array{array{id: int, date: ?\DateTimeImmutable}|null, list<array{id: int, date: ?\DateTimeImmutable, cancelled: bool}>}
     */
    private function hearingTerms(CaseFileEvent $event): array
    {
        if ($event->eventOrder === null && $event->parentEventOrder === null) {
            return [null, []]; // unaddressable record, nothing can link to it
        }
        $partOf = null;
        $terms = [];
        foreach ($this->events->findByCaseFileAndSource($event->caseFileId, $event->source) as $row) {
            if ($row->id === $event->id || $row->eventCode !== $event->eventCode
                || self::ownerKey($row) !== self::ownerKey($event)) {
                continue;
            }
            if ($event->parentEventOrder !== null && $row->parentEventOrder === null
                && $row->eventOrder === $event->parentEventOrder) {
                $partOf = ['id' => $row->id, 'date' => $row->eventDate];
            }
            if ($event->eventOrder !== null && $row->parentEventOrder === $event->eventOrder) {
                $terms[] = ['id' => $row->id, 'date' => $row->eventDate, 'cancelled' => $row->cancelled];
            }
        }
        return [$partOf, $terms];
    }


    /**
     * Identity of the record within one case, minus the poradi: the pairing
     * a multi-term hearing block resolves against (code + owner case).
     */
    private static function ownerKey(CaseFileEvent $event): string
    {
        return implode('|', [
            $event->refCourtKod ?? '',
            $event->refRegistryNorm ?? '',
            $event->refSenate ?? '',
            $event->refBcNumber ?? '',
            $event->refYear ?? '',
        ]);
    }


    /** Full record identity of an own or foreign event under a given poradi. */
    private static function recordKey(CaseFileEvent $event, int $order): string
    {
        return $event->eventCode . '|' . $order . '|' . self::ownerKey($event);
    }


    /**
     * Attribute rows of the event detail, mirroring the SPA rendering rules
     * (flag attribute, "|" separators, "-" = not stated).
     *
     * @param array<mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function attributes(CaseContext $context, array $detail, CourtLevel $ownerLevel): array
    {
        // Flat map of the detail's attributes, so a case reference can read the
        // sibling attribute naming its court (PR_VEC_NS -> ODVOL_SOUD).
        $values = InfosoudEventAttribute::mapFromDetail($detail);
        $relatedCourts = $this->relatedCourtIndex($context);

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
     * Linked cases from the event detail's navazneVeci (e.g. the appellate
     * case of an ODVOLANI event).
     *
     * @param array<mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function navazneVeci(CaseContext $context, array $detail): array
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
                'typeLabel' => InfosoudEventAttribute::label($reference['typ'], $context->court->level),
                'cached' => $cached,
            ] + $this->chips->chip($court, $reference['spisovka']);
        }
        return $items;
    }


    /** SPA deep-link of the event (null when it cannot be addressed upstream). */
    private function eventInfosoudUrl(CaseContext $context, CaseFileEvent $event): ?string
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
            $context->spisovka,
            $context->court,
            $event->eventCode,
            $event->eventOrder,
            $owner,
            $ownerCourt,
            $event->upstreamId,
        );
    }


    /**
     * Identity keys of every case this one is related to, mapped to the court
     * kod (null when the relation carries no court).
     *
     * @return array<string, ?string>
     */
    private function relatedCourtIndex(CaseContext $context): array
    {
        [$src, $dst] = $this->relationRows($context);
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
     * Relations of the case in both directions, read once per case - the
     * related table and the court index of a page both need them.
     *
     * @return array{list<CaseFileRelation>, list<CaseFileRelation>} src side, dst side
     */
    private function relationRows(CaseContext $context): array
    {
        $case = $context->case;
        if ($this->relations !== null && $this->relationsOf === $case->id) {
            return $this->relations;
        }
        // Senate null for the Supreme Court: other courts' projections record a
        // reference to a NS case with senate 0 instead of the real number, so
        // matching on it would hide every relation pointing at this case.
        $identity = [
            $case->courtKod, $case->registryNorm,
            $context->court->level === CourtLevel::Supreme ? null : $case->senate,
            $case->bcNumber, $case->year,
        ];
        $this->relationsOf = $case->id;
        return $this->relations = [
            $this->relationRepository->findBySrc(...$identity),
            $this->relationRepository->findByDst(...$identity),
        ];
    }
}
