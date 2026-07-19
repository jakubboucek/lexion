<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Czech labels and tooltips of infosoud event codes, extracted verbatim from
 * the infosoud SPA i18n bundle (chunk-YAVSMO7F.js, 2026-07-18) - see
 * docs/data/infosoud-ciselniky.json. The Supreme Court (NS) uses a distinct
 * set for several codes (e.g. ZAHAJ_RIZ = "Došlo Nejvyššímu soudu"), so pass
 * $supreme = true for NS proceedings. Unknown codes fall back to the raw code
 * (label) or null (tooltip) so nothing is ever hidden.
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

    /** Supreme Court label overrides (only codes that differ). */
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

    /** General tooltips (plain text). */
    private const array Tooltips = [
        'NAR_JED' => 'Nařízení jednání je naplánování jednání do jednacího kalendáře soudu',
        'ODES_SPIS' => 'Odesláním spisu se rozumí: např.: odeslání nadřízenému soudu k prostudování',
        'PNS_NASTUP' => 'Druhem rozhodnutí ve věci bylo rozhodnutí o procesním nástupnictvím',
        'PNS_ODLOZ' => 'Druhem rozhodnutí ve věci bylo usnesení o odložení vykonavatelnosti',
        'PNS_VYZVA' => 'Druhem rozhodnutí ve věci byla Výzva',
        'POD_OP_PR' => 'Podání opravného prostředku je např. odvolání některého z účastníků řízení k vydanému rozhodnutí ve věci',
        'PR_VEC_NS' => 'Datum kdy bylo přerušeno soudní řízení',
        'SPIS_K_SC' => 'Odesláním spisu se rozumí odeslání spisu soudci',
        'SPIS_K_SO' => 'Odesláním spisu se rozumí odeslání spisu soudnímu komisaři',
        'SPIS_OD_SC' => 'Předáním spisu soudcem se rozumí vrácení spisu od soudce',
        'SPIS_OD_SO' => 'Předáním spisu soudním komisařem se rozumí vrácení spisu od soudního komisaře',
        'ST_VEC_OBZ' => 'Obživnutím věci se myslí zrušení původního vyřizujícího rozhodnutí a v soudním řízení se pokračuje',
        'ST_VEC_ODS' => 'Tzv. "Odškrtnutí" věci, nastane v okamžiku vyřízení všech náležitostí souvisejících s řízením',
        'ST_VEC_PRE' => 'Přerušením řízení věci nastává např.: v případě prohlášení konkurzu na účastníka řízení',
        'ST_VEC_PUK' => 'Datum pravomocného ukončení věci',
        'ST_VEC_UPR' => 'Ukončeno přerušení řízení',
        'ST_VEC_VYR' => 'Vyřízením věci se myslí vyřízení věci',
        'VRAC_SPIS' => 'Vrácením spisu se rozumí: např.: na vrácení spisu od nadřízeného soudu',
        'VR_SP_NS' => 'Datem vrácení spisu se myslí vrácení spisu odvolacímu soudu',
        'VYD_ROZH' => 'Vydání rozhodnutí je rozhodnutí soudce nebo vyšší soudní úřednice',
        'VYR_OP_PR' => 'Opravný prostředek je vyřízen např. nadřízeným krajským soudem, kde je vydáno nové rozhodnutí',
        'ZAHAJ_RIZ' => 'Zahájení řízení je zápis spisové značky do příslušného rejstříku',
        'ZRUS_JED' => 'Zrušení nařízeného jednání',
        'PREVD_SPIS' => 'Událostí Převedeno se rozumí: ukončení zpracování věci a převedení dalšího řízení pod jinou spisovou značku',
        'DOVOL_RIZ' => 'Řízení o opravném prostředku na Nejvyšším soudu ČR se myslí zahájení dovolacího řízení na Nejvyšším soudu',
        'ODVOLANI' => 'Řízení o opraveném prostředku u krajského a vrchního soudu se myslí zahájení odvolacího řízení',
        'NAD_RIZENI' => 'Řízení u nadřízeného soudu se myslí zahájení souvisejícího řízení na nadřízeném soudu',
        'PNS_ODL_PM' => 'Poznámka o procesních opatřeních - Odložení právní moci',
    ];

    /** Supreme Court tooltip overrides. */
    private const array SupremeTooltips = [
        'NAR_JED' => 'Nařízení jednání je naplánování jednání do jednacího kalendáře soudu',
        'ODES_SPIS' => 'Spisem SO se myslí odeslání spisu znalci nebo jiným státním orgánům',
        'PNS_NASTUP' => 'Druhem rozhodnutí ve věci bylo rozhodnutí o procesním nástupnictvím',
        'PNS_ODLOZ' => 'Druhem rozhodnutí ve věci bylo usnesení o odložení vykonavatelnosti',
        'PNS_VYZVA' => 'Druhem rozhodnutí ve věci byla Výzva',
        'PR_VEC_NS' => 'Datum kdy bylo přerušeno soudní řízení',
        'ST_VEC_PUK' => 'Datum právní moci',
        'ST_VEC_VYR' => 'Datum, kdy bylo vyznačeno vyřízení věci. Údaj by měl být shodný s datem vydání vyřizujícího rozhodnutí',
        'VR_SP_NS' => 'Datem vrácení spisu se myslí vrácení spisu odvolacímu soudu',
        'VYD_ROZH' => 'Vydání rozhodnutí je rozhodnutí soudce nebo vyšší soudní úřednice',
        'ZAHAJ_RIZ' => 'Bylo zahájeno soudní řízení na Nejvyšším soudu',
        'ZRUS_JED' => 'Zrušení nařízeného jednání',
        'PNS_ODL_PM' => 'Poznámka o procesních opatřeních - Odložení právní moci',
        'ST_VEC_OBZ' => 'Obživnutím věci se myslí zrušení původního vyřizujícího rozhodnutí a v soudním řízení se pokračuje',
        'ST_VEC_ODS' => 'Datem vrácení spisu se myslí vrácení spisu odvolacímu soudu',
    ];


    /** Past-tense one-sentence descriptions (SPA: rizeni:udalost.popis.*). */
    private const array Descriptions = [
        'NAR_JED' => 'Bylo nařízeno jednání ve věci',
        'ODES_SPIS' => 'Spis byl odeslán',
        'POD_OP_PR' => 'Byl podán opravný prostředek ve věci',
        'SPIS_K_SC' => 'Spis byl odeslán soudci',
        'SPIS_K_SO' => 'Spis byl odeslán soudnímu komisaři',
        'SPIS_OD_SC' => 'Soudce předal spis zpět',
        'SPIS_OD_SO' => 'Soudní komisař předal spis zpět',
        'ST_VEC_OBZ' => 'Věc byla obživnuta',
        'ST_VEC_ODS' => 'Skončení věci a uložení na spisovnu nebo převedení pod jinou spisovou značku',
        'ST_VEC_PRE' => 'Bylo přerušeno soudní řízení',
        'ST_VEC_PUK' => 'Datum pravomocného ukončení věci',
        'ST_VEC_UPR' => 'Bylo ukončeno přerušení věci a bude pokračováno v soudním řízení',
        'ST_VEC_VYR' => 'Věc se vyřídila',
        'VRAC_SPIS' => 'Spis byl vrácen na soud',
        'VYD_ROZH' => 'Bylo vydáno rozhodnutí ve věci',
        'VYR_OP_PR' => 'Opravný prostředek byl vyřízen',
        'ZAHAJ_RIZ' => 'Bylo zahájeno soudní řízení',
        'ZRUS_JED' => 'Bylo zrušeno jednání ve věci',
        'PREVD_SPIS' => 'Převedeno řízení pod jinou spisovou značku',
        'DOVOL_RIZ' => 'Ve spisu byl podán opravný prostředek',
        'ODVOLANI' => 'Ve spisu byl podán opravný prostředek',
        'NAD_RIZENI' => 'Ve spisu byl podán opravný prostředek',
    ];

    /**
     * NS descriptions: the SPA set is explicit blanks except this one - the
     * general set must NOT be used as a fallback for NS.
     */
    private const array SupremeDescriptions = [
        'ST_VEC_PUK' => 'Datum právní moci',
    ];


    public static function label(string $code, bool $supreme = false): string
    {
        if ($supreme && isset(self::SupremeLabels[$code])) {
            return self::SupremeLabels[$code];
        }
        return self::Labels[$code] ?? $code;
    }


    public static function tooltip(string $code, bool $supreme = false): ?string
    {
        if ($supreme && isset(self::SupremeTooltips[$code])) {
            return self::SupremeTooltips[$code];
        }
        return self::Tooltips[$code] ?? null;
    }


    public static function description(string $code, bool $supreme = false): ?string
    {
        if ($supreme) {
            return self::SupremeDescriptions[$code] ?? null;
        }
        return self::Descriptions[$code] ?? null;
    }
}
