<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Supreme Court collegium (kolegium) derived from the registry code, extracted
 * verbatim from the infosoud SPA (chunk-ZFASXX42.js, 2026-07-26).
 *
 * The SPA shows it in place of the case state for Supreme Court cases
 * (`typOrganizace == "ns"`), which have no state of their own. Registries
 * outside these lists have no collegium; the SPA then falls back to printing
 * the raw registry code, which tells the user nothing, so we print nothing.
 *
 * Note the lists carry the API/norm form of the registry ("CDO"), and two of
 * them ("1SKNO", "2SKNO") start with a digit - upstream treats that digit as
 * part of the registry code, while our parser would read it as the senate. No
 * such case exists in our data yet, see the TODO in docs/infosoud-api.md.
 */
final class InfosoudCollegium
{
    private const array Collegia = [
        'trestní' => ['TZ', 'TDO', 'TVO', 'TCU', 'TPJN', 'TS', 'TD', 'NTN', '1SKNO', '2SKNO'],
        'občanskoprávní a obchodní' => ['CDO', 'NCU', 'NCN', 'CPJN', 'ND', 'INS'],
        'obchodní' => ['ODO', 'NON', 'OPJN', 'OD'],
        'určení lhůty' => ['TUL', 'CUL'],
    ];


    /** Collegium label for a registry in its norm form, or null when unknown. */
    public static function forRegistry(string $registryNorm): ?string
    {
        foreach (self::Collegia as $label => $registries) {
            if (in_array($registryNorm, $registries, true)) {
                return $label;
            }
        }
        return null;
    }
}
