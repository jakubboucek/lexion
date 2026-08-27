<?php declare(strict_types=1);

namespace App\Model\Infosoud;

use App\Model\Codelist\CourtLevel;


/**
 * Which case identities may be sent to the infosoud API at all.
 *
 * Two kinds of refusal, told apart by their validation code and by whether
 * the court matters (both verified empirically, see docs/infosoud-api.md,
 * "Povolené rejstříky"):
 *
 * - **juvenile justice** (Tm, Tmo, Ntm) - HTTP 400 RIZENI_VALIDATION_0002
 *   ("druh věci"), refused at every court level. The SPA form whitelist omits
 *   them too, matching the deliberate exclusion of juvenile criminal
 *   proceedings from public lookup (Act No. 218/2003 Coll.);
 * - **Nc at regional and high courts** - HTTP 400 RIZENI_VALIDATION_0005
 *   ("Byla zadaná chybná agenda"), while the very same registry answers
 *   normally at district courts and at the Supreme Court. The refusal is
 *   therefore about the court, not the registry, and must never be raised
 *   before the court is known.
 *
 * Asking anyway would mean sending requests the official SPA would never
 * emit, so both are refused on our side before any HTTP happens.
 */
final class InfosoudQueryPolicy
{
    /** Registries (norm form) never sent to infosoud, whichever the court. */
    private const array BlockedRegistryNorms = ['TM', 'TMO', 'NTM'];

    /** Registries refused by some court levels only, keyed by registry norm. */
    private const array BlockedAtLevels = ['NC' => [CourtLevel::Regional, CourtLevel::High]];


    /**
     * Whether the registry is answerable at SOME court level. A registry that
     * passes here may still be refused at a particular court - the file number
     * alone cannot settle that, so ask isQueryableAt() once the court is known.
     */
    public static function isQueryableRegistry(string $registryNorm): bool
    {
        return !in_array($registryNorm, self::BlockedRegistryNorms, true);
    }


    /** Whether the registry is answerable at this very court level. */
    public static function isQueryableAt(string $registryNorm, CourtLevel $level): bool
    {
        return self::isQueryableRegistry($registryNorm)
            && !in_array($level, self::BlockedAtLevels[$registryNorm] ?? [], true);
    }


    /**
     * Why the identity cannot be asked about, as a sentence for the user, or
     * null when it can. The wording is the policy's own business: the two
     * refusals have entirely different reasons and must not be phrased alike.
     *
     * @param string $registryDisplay registry as the codelist spells it ("P a Nc")
     */
    public static function refusalReason(string $registryNorm, string $registryDisplay, ?CourtLevel $level): ?string
    {
        if (!self::isQueryableRegistry($registryNorm)) {
            return sprintf(
                'Rejstřík „%s“ patří do soudnictví ve věcech mládeže – tato řízení infoSoud'
                . ' z důvodu ochrany mladistvých neposkytuje a Lexion je proto nemůže vyhledat.',
                $registryDisplay,
            );
        }
        if ($level !== null && !self::isQueryableAt($registryNorm, $level)) {
            return sprintf(
                'Rejstřík „%s“ infoSoud u krajských a vrchních soudů nevyhledává (odmítá jej jako'
                . ' chybnou agendu) – u okresních soudů přitom funguje. Zkontrolujte, zda značka'
                . ' patří opravdu k tomuto soudu.',
                $registryDisplay,
            );
        }
        return null;
    }
}
