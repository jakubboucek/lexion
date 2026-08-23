<?php declare(strict_types=1);

namespace App\Model\Sync;

use Nette;


/**
 * The one rule every merge in the sync leans on, in one place so the domains
 * cannot drift apart on it.
 *
 * Freshness is domain freshness: the stamps that say when the data was
 * fetched from the source (`infosoud_at`, `detail_fetched_at`, `last_seen_at`,
 * an observation's `observed_at`), never `updated_at` - on the receiving side
 * that column means "when I imported it", so using it would let a fresh
 * import lose to its own bookkeeping.
 */
final class Freshness
{
    use Nette\StaticClass;

    /** Is $a a strictly fresher acquisition than $b? A missing stamp is the oldest. */
    public static function isNewer(?\DateTimeInterface $a, ?\DateTimeInterface $b): bool
    {
        return $a !== null && ($b === null || $a > $b);
    }
}
