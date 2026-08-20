<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Infosoud\InfosoudCaseOverview;
use App\Model\Infosoud\InfosoudEventAttribute;


/**
 * Derived case-level summary values (first-event attributes, subject, status)
 * of a stored case file. Extracted from SpisPresenter so list views
 * (Panel dashboard) can reuse them without duplicating the JSON digging.
 */
final readonly class CaseSummaryService
{
    public function __construct(
        private ProceedingEventRepository $events,
    ) {
    }


    /**
     * Attributes of the case's first own event (subject, PRED_VEC, NS senate
     * info) as a typ => hodnota map. The authoritative source is the projected
     * event row - its detail is kept fresh by both the sync seed and the lazy
     * per-event fetch, so it exists even when the raw JSON lacks the
     * firstEventDetail snapshot (e.g. cases once fetched by the CLI tool).
     * The snapshot is only a fallback.
     *
     * @return array<string, ?string> values normalized ('-'/blank => null)
     */
    public function attributesOf(CaseFile $case): array
    {
        return InfosoudEventAttribute::mapFromList(
            $this->rawAttributesFrom($case, $this->events->findByCaseFile($case->id)),
        );
    }


    /** Case subject (PREDM_RIZ), if known and stated. */
    public function subjectOf(CaseFile $case): ?string
    {
        return $this->subjectFrom($this->attributesOf($case));
    }


    /**
     * Subjects of many cases at once, keyed by case id - list views (dashboard,
     * related cases) would otherwise spend one events query per row.
     *
     * @param list<CaseFile> $cases
     * @return array<int, ?string>
     */
    public function subjectsOf(array $cases): array
    {
        $events = $this->events->findByCaseFiles(
            array_map(static fn(CaseFile $case): int => $case->id, $cases),
        );
        $subjects = [];
        foreach ($cases as $case) {
            $subjects[$case->id] = $this->subjectFrom(InfosoudEventAttribute::mapFromList(
                $this->rawAttributesFrom($case, $events[$case->id] ?? []),
            ));
        }
        return $subjects;
    }


    /**
     * Subject from an already-built attributesOf() map - avoids a second
     * event-table pass when the caller needs the full map anyway.
     *
     * @param array<string, ?string> $attributes
     */
    public function subjectFrom(array $attributes): ?string
    {
        return $attributes['PREDM_RIZ'] ?? null;
    }


    /** Current state of the case (infosoud "stav"), if known. */
    public function statusOf(CaseFile $case): ?string
    {
        // The overview struct owns the upstream key, incl. blank-to-null.
        return InfosoudCaseOverview::fromJson($case->infosoudJson)->status();
    }


    /**
     * @param list<CaseFileEvent> $events timeline of the case, in timeline order
     * @return array<mixed> raw attribute list (items of shape {typ, hodnota})
     */
    private function rawAttributesFrom(CaseFile $case, array $events): array
    {
        $earliest = null;
        foreach ($events as $event) {
            if ($event->refRegistryNorm !== null || $event->detailJson === null) {
                continue; // foreign event or thin row
            }
            if ($event->eventCode === 'ZAHAJ_RIZ') {
                $earliest = $event;
                break;
            }
            $earliest ??= $event; // rows come date-ordered; mirror pickFirstOwnEvent()
        }
        if ($earliest !== null) {
            $detail = StoredJson::decode($earliest->detailJson, "event #{$earliest->id} (detail_json)");
            if (is_array($detail['atributy'] ?? null)) {
                return $detail['atributy'];
            }
        }
        $snapshot = StoredJson::decode(
            $case->infosoudJson,
            "case file #{$case->id} (infosoud_json)",
        )['firstEventDetail']['atributy'] ?? null;
        return is_array($snapshot) ? $snapshot : [];
    }
}
