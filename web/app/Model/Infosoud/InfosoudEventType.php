<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Czech labels of infosoud event codes. Labels verified against the infosoud
 * SPA where possible; unknown codes fall back to the raw code so nothing is
 * ever hidden. Extend as new codes appear in harvested data.
 */
final class InfosoudEventType
{
    private const array Labels = [
        'ZAHAJ_RIZ' => 'Zahájení řízení',
        'VYD_ROZH' => 'Vydání rozhodnutí',
        'ST_VEC_VYR' => 'Vyřízení věci',
        'ST_VEC_PUK' => 'Datum pravomocného ukončení věci',
        'ST_VEC_ODS' => 'Skončení věci',
        'ST_VEC_OBZ' => 'Obživnutí věci',
        'NAR_JED' => 'Nařízení jednání',
        'ZRUS_JED' => 'Zrušení jednání',
        'ODVOLANI' => 'Odvolání',
        'POD_OP_PR' => 'Podání opravného prostředku',
        'VYR_OP_PR' => 'Vyřízení opravného prostředku',
        'ODES_SPIS' => 'Odeslání spisu',
        'VRAC_SPIS' => 'Vrácení spisu',
        'VR_SP_NS' => 'Datum vrácení spisu',
        'PNS_VYZVA' => 'Poznámka o procesních opatřeních – výzva',
    ];


    public static function label(string $code): string
    {
        return self::Labels[$code] ?? $code;
    }
}
