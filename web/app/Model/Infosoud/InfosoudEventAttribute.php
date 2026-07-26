<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Czech labels of event-detail attribute types, extracted verbatim from the
 * infosoud SPA i18n bundle (chunk-YAVSMO7F.js, 2026-07-19) - see
 * docs/data/infosoud-ciselniky.json. The SPA applies per-court overrides:
 * a dedicated set for the Supreme Court and a single regional-court override
 * (OP_D_PODA). Unknown types fall back to the raw type code.
 *
 * Rendering quirks (mirrored from the SPA DetailUdalostiComponent):
 * NAVRH_PR is a flag attribute - only its label is shown, and only when the
 * value is "#TRUE"; values may carry "|" separators (SLOZENI_SENATU) which
 * display as ", ".
 */
final class InfosoudEventAttribute
{
    /** Flag attribute: label-only display, shown only when value is "#TRUE". */
    public const string FlagAttribute = 'NAVRH_PR';
    public const string FlagTrue = '#TRUE';

    /**
     * Attributes whose value is a file number of another case ("2 ZT 7 / 2025").
     * The value is upstream free text and need not be a court case at all (ZT is
     * a prosecutor's registry), so it is only ever offered as a link once it
     * parses and its registry is in the codelist - see SpisPresenter.
     */
    private const array CaseReferences = [
        'PRED_VEC',
        'PO_VEC',
        'PREVD_SPZN',
        'PR_VEC_NS',
        'SPISOVA_ZNACKA_NADRIZENEHO_SOUDU',
    ];


    /**
     * Attribute naming the court of a case reference, keyed by the reference
     * attribute. The reference itself carries no court, but a sibling attribute
     * names it in plain text matching the codelist ("Městský soud Praha"), which
     * is the only way to resolve it.
     */
    private const array CourtNamedBy = [
        'PR_VEC_NS' => 'ODVOL_SOUD',   // file number of the decision under review, at the appellate court
    ];


    public static function isCaseReference(string $type): bool
    {
        return in_array($type, self::CaseReferences, true);
    }


    /** Attribute type naming the court of the given case reference, if any. */
    public static function courtNamedBy(string $type): ?string
    {
        return self::CourtNamedBy[$type] ?? null;
    }

    /** General labels (district / regional / high courts). */
    private const array Labels = [
        'D_SENAT' => 'Datum přidělení senátu',
        'JED_D_ZAC' => 'Začátek jednání',
        'JED_D_Z_V' => 'Datum zápisu výsledku',
        'JED_SIN' => 'Jednací síň',
        'JED_VYSLED' => 'Výsledek',
        'JED_ZRUS' => 'Jednání zrušeno',
        'JED_DRUH' => 'Druh jednání',
        'NAVRH_PR' => 'Podán návrh na vydání platebního rozkazu',
        'ODVOL_SOUD' => 'Odvolací soud',
        'OD_SP_D_OD' => 'Datum odeslání',
        'OD_SP_D_OV' => 'Očekávané vrácení',
        'OD_SP_D_VR' => 'Vrácení spisu',
        'OD_SP_KOMU' => 'Odesláno komu',
        'OD_SP_UCEL' => 'Účel odeslání',
        'OP_DRUH' => 'Druh opravného prostředku',
        'OP_DR_ROZH' => 'Opravný prostředek vyřízen – druh rozhodnutí',
        'OP_D_DOSLO' => 'Datum podání opravného prostředku na soud',
        'OP_D_DOSO' => 'Datum podání opravného prostředku na poště',
        'OP_D_P2' => 'Datum předložení II. stupni',
        'OP_D_PODA' => 'Datum podání opravného prostředku na poště',
        'OP_D_ROZH' => 'Opravný prostředek podán proti – druh rozhodnutí',
        'OP_D_VYD' => 'Opravný prostředek podán proti rozhodnutí ze dne',
        'OP_D_VYD_O' => 'Opravný prostředek vyřízen dne',
        'OP_D_VYR' => 'Datum vyřízení',
        'OP_ROZ_O_O' => 'Rozhodnutí o opr.',
        'OP_ROZ_PR' => 'Rozhodnutí proti',
        'OP_VYSL' => 'Výsledek opravného prostředku',
        'OP_V_2ST' => 'Datum vyřízení nadřízeným soudem',
        'OS_SP_D_VR' => 'Vrácení spisu',
        'OS_VRAC' => 'Odesláno komu',
        'PO_VEC' => 'Navazující věc',
        'PREDM_RIZ' => 'Předmět řízení',
        'PRED_VEC' => 'Předcházející věc',
        'PREVD_D_OD' => 'Datum převedení',
        'PREVD_SOUD' => 'Navazující soud',
        'PREVD_SPZN' => 'Navazující spisová značka',
        'PR_VEC_NS' => 'Spisová značka napadeného rozhodnutí odvolacího soudu',
        'ROZH_DR_RO' => 'Druh rozhodnutí',
        'ROZH_DR_S' => 'Druh soudu',
        'ROZH_D_DIK' => 'Datum nadiktování',
        'ROZH_D_PM' => 'Datum právní moci',
        'ROZH_D_VYD' => 'Datum vydání',
        'ROZH_D_VYP' => 'Datum vypravení',
        'ROZH_D_ZRU' => 'Datum zrušení',
        'ROZH_KON' => 'Rozhodnutí vyřizuje věc',
        'ROZH_VYS' => 'Výsledek',
        'ROZH_ZRUS' => 'Rozhodnutí bylo zrušeno',
        'SENAT' => 'Senát',
        'SPIS_KS_D' => 'Datum odeslání',
        'SPIS_OD_D' => 'Datum předání',
        'STAV_VECI' => 'Stav věci',
        'SLOZENI_SENATU' => 'Složení senátu',
        'ST_VEC_D_D' => 'Datum',
        'ST_VEC_UPR' => 'Upřesnění',
        'ST_VEC_ZVR' => 'Způsob vyřízení',
        'VR_SP_D_VR' => 'Datum vrácení',
        'ZPRAVODAJ' => 'Složení senátu',
        'SPISOVA_ZNACKA_NADRIZENEHO_SOUDU' => 'Spisová značka nadřízeného soudu',
        'NADRIZENY_SOUD' => 'Nadřízený soud',
    ];

    /** Supreme Court overrides (SPA: rizeni:atribut.ns.*). */
    private const array SupremeOverrides = [
        'D_SENAT' => 'Datum přidělení senátu',
        'NAVRH_PR' => 'Podán návrh na vydání platebního rozkazu',
        'ODVOL_SOUD' => 'Odvolací soud',
        'OD_SP_D_VR' => 'Vrácení spisu',
        'OD_SP_KOMU' => 'Odesláno komu',
        'OS_VRAC' => 'Odesláno komu',
        'PR_VEC_NS' => 'Spisová značka napadeného rozhodnutí odvolacího soudu',
        'SENAT' => 'Senát',
        'STAV_VECI' => 'Stav věci',
        'ST_VEC_D_D' => 'Datum',
        'ST_VEC_UPR' => 'Upřesnění',
        'ST_VEC_ZVR' => 'Způsob vyřízení',
        'ZPRAVODAJ' => 'Zpravodaj',
        'SLOZENI_SENATU' => 'Složení senátu',
    ];

    /** Regional-court overrides (SPA applies only this single one). */
    private const array RegionalOverrides = [
        'OP_D_PODA' => 'Datum doručení OP soudu',
    ];


    /** @param string $courtLevel our court.level value ('os', 'ks', 'vs', 'ns') */
    public static function label(string $type, string $courtLevel): string
    {
        if ($courtLevel === 'ns' && isset(self::SupremeOverrides[$type])) {
            return self::SupremeOverrides[$type];
        }
        if ($courtLevel === 'ks' && isset(self::RegionalOverrides[$type])) {
            return self::RegionalOverrides[$type];
        }
        return self::Labels[$type] ?? $type;
    }
}
