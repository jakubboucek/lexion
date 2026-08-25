<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Which case identities may be sent to the infosoud API at all.
 *
 * The API refuses juvenile-justice registries (Tm at OS, Tmo at KS - HTTP 400
 * RIZENI_VALIDATION_0002, verified empirically 2026-08-25) and the official
 * SPA form whitelist omits Tm/Tmo/Ntm at every court level, matching the
 * deliberate exclusion of juvenile criminal proceedings from public lookup
 * (Act No. 218/2003 Coll.). Asking anyway would mean sending requests the
 * official SPA would never emit, so they are refused on our side before any
 * HTTP happens. See docs/infosoud-api.md, "Povolene rejstriky (druhVeci)".
 */
final class InfosoudQueryPolicy
{
    /** Registries (norm form) never sent to infosoud, at any court level. */
    private const array BlockedRegistryNorms = ['TM', 'TMO', 'NTM'];


    public static function isQueryableRegistry(string $registryNorm): bool
    {
        return !in_array($registryNorm, self::BlockedRegistryNorms, true);
    }
}
