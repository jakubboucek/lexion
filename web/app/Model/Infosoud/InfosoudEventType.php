<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Czech labels of infosoud event codes, extracted verbatim from the infosoud
 * SPA i18n bundle (chunk-YAVSMO7F.js, 2026-07-18) - see
 * docs/data/infosoud-ciselniky.json. The Supreme Court (NS) uses a distinct
 * label set for several codes (e.g. ZAHAJ_RIZ = "Došlo Nejvyššímu soudu"),
 * so pass $supreme = true for NS proceedings. Unknown codes fall back to the
 * raw code so nothing is ever hidden.
 */
final class InfosoudEventType
{
    /** General labels (district / regional / high courts). */
    private const array Labels = [
        'NAR_JED' => 'Nařízení jednání',
        'ODES_SPIS' => 'Odeslání spisu',
        'PNS_NASTUP' => 'Poznámka o procesních opatřeních - Procesní nástupnictví',
        'PNS_ODLOZ' => 'Poznámka o procesních opatřeních - Usnesení o odložení vykonavatelnosti',
        'PNS_VYZVA' => 'Poznámka o procesních opatřeních - Výzva',
        'POD_OP_PR' => 'Podán opravný prostředek',
        'PR_VEC_NS' => 'Datum přerušení věci',
        'SPIS_K_SC' => 'Odeslání spisu k soudci',
        'SPIS_K_SO' => 'Odeslání spisu k soudnímu komisaři',
        'SPIS_OD_SC' => 'Soudce předal spis',
        'SPIS_OD_SO' => 'Soudní komisař předal spis',
        'ST_VEC_OBZ' => 'Obživnutí věci',
        'ST_VEC_ODS' => 'Skončení věci',
        'ST_VEC_PRE' => 'Přerušení řízení',
        'ST_VEC_PUK' => 'Datum pravomocného ukončení věci',
        'ST_VEC_UPR' => 'Pokračování řízení',
        'ST_VEC_VYR' => 'Vyřízení věci',
        'VRAC_SPIS' => 'Vrácení spisu',
        'VR_SP_NS' => 'Datum vrácení spisu',
        'VYD_ROZH' => 'Vydání rozhodnutí',
        'VYR_OP_PR' => 'Vyřízení opravného prostředku',
        'ZAHAJ_RIZ' => 'Zahájení řízení',
        'ZRUS_JED' => 'Zrušení jednání',
        'PREVD_SPIS' => 'Převedeno',
        'DOVOL_RIZ' => 'Řízení o opravném prostředku na Nejvyšším soudu ČR',
        'ODVOLANI' => 'Řízení o opravném prostředku u krajského a vrchního soudu',
        'NAD_RIZENI' => 'Řízení u nadřízeného soudu',
        'PNS_ODL_PM' => 'Poznámka o procesních opatřeních - Odložení právní moci',
    ];

    /** Supreme Court overrides (only codes that differ from the general set). */
    private const array SupremeLabels = [
        'NAR_JED' => 'Datum nařízení jednání',
        'ODES_SPIS' => 'Poznámka o procesních opatřeních - Spis SO',
        'PNS_NASTUP' => 'Poznámka o procesních opatřeních - Procesní nástupnictví',
        'PNS_ODLOZ' => 'Poznámka o procesních opatřeních - Usnesení o odložení vykonavatelnosti',
        'PNS_VYZVA' => 'Poznámka o procesních opatřeních - Výzva',
        'PR_VEC_NS' => 'Datum přerušení věci',
        'ST_VEC_PUK' => 'Datum právní moci',
        'ST_VEC_VYR' => 'Datum vyřízení věci',
        'VR_SP_NS' => 'Datum vrácení spisu',
        'VYD_ROZH' => 'Datum vydání rozhodnutí',
        'ZAHAJ_RIZ' => 'Došlo Nejvyššímu soudu',
        'ZRUS_JED' => 'Datum zrušení jednání',
        'PNS_ODL_PM' => 'Poznámka o procesních opatřeních - Odložení právní moci',
        'ST_VEC_OBZ' => 'Datum obživnutí věci',
        'ST_VEC_ODS' => 'Datum vrácení spisu',
    ];


    public static function label(string $code, bool $supreme = false): string
    {
        if ($supreme && isset(self::SupremeLabels[$code])) {
            return self::SupremeLabels[$code];
        }
        return self::Labels[$code] ?? $code;
    }
}
