<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Infosoud\InfosoudEventAttribute;
use Nette\Utils\Json;


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
        return InfosoudEventAttribute::mapFromList($this->rawAttributesOf($case));
    }


    /** Case subject (PREDM_RIZ), if known and stated. */
    public function subjectOf(CaseFile $case): ?string
    {
        return $this->subjectFrom($this->attributesOf($case));
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
        $status = trim((string) ($this->decodedInfosoud($case)['stav'] ?? ''));
        return $status !== '' ? $status : null;
    }


    /** @return array<mixed> raw attribute list (items of shape {typ, hodnota}) */
    private function rawAttributesOf(CaseFile $case): array
    {
        $earliest = null;
        foreach ($this->events->findByCaseFile($case->id) as $event) {
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
            $detail = Json::decode((string) $earliest->detailJson, forceArrays: true);
            if (is_array($detail) && is_array($detail['atributy'] ?? null)) {
                return $detail['atributy'];
            }
        }
        $snapshot = $this->decodedInfosoud($case)['firstEventDetail']['atributy'] ?? null;
        return is_array($snapshot) ? $snapshot : [];
    }


    /** @return array<mixed> decoded infosoud payload ([] when absent) */
    private function decodedInfosoud(CaseFile $case): array
    {
        if ($case->infosoudJson === null) {
            return [];
        }
        $decoded = Json::decode($case->infosoudJson, forceArrays: true);
        return is_array($decoded) ? $decoded : [];
    }
}
