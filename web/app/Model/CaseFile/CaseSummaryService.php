<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Infosoud\InfosoudEventAttribute;


/**
 * Attributes of a case's first own event that are NOT materialized into
 * columns: the Supreme Court extras (SENAT, SLOZENI_SENATU, ODVOL_SOUD,
 * PR_VEC_NS), which only NS cases carry and which the case header renders
 * verbatim.
 *
 * This is the one deliberate exception to "pages do not read raw JSON"
 * (decided 2026-08-26): four NS-only columns on case_file would model a whole
 * event detail in the case table for a fraction of the traffic. Everything
 * every case needs - subject, status, hearing values - lives in columns
 * written by CaseSummaryExtraction, and reading them needs no service at all.
 */
final readonly class CaseSummaryService
{
    public function __construct(
        private CaseFileEventRepository $events,
    ) {
    }


    /**
     * @return array<string, ?string> values normalized ('-'/blank => null)
     * @throws StoredJsonException stored payload unreadable (data integrity)
     */
    public function attributesOf(CaseFile $case): array
    {
        $first = CaseSummaryExtraction::firstOwnDetailed($this->events->findByCaseFile($case->id));
        if ($first === null) {
            return [];
        }
        $detail = StoredJson::decode($first->detailJson, "event #{$first->id} (detail_json)");
        return InfosoudEventAttribute::mapFromDetail($detail);
    }
}
