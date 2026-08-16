<?php declare(strict_types=1);

namespace App\Model\Proceeding;


/**
 * When ProceedingSyncService::ensureLoaded() may spend a request on infosoud.
 * Being explicit here is what keeps the three callers honest: they want
 * genuinely different things from the same "make sure we hold this case".
 */
enum CaseLoadPolicy
{
    /**
     * Any row we hold is enough. The homepage only verifies the case exists
     * before linking to it and must not spend a request on a case we already
     * know from another source (an ISIR import, say).
     */
    case AnySource;

    /**
     * A row with infosoud data, because that is what gets rendered - a case we
     * only know from another source is fetched.
     */
    case InfosoudData;

    /**
     * Ask upstream regardless of what we hold: the manual refresh button. The
     * caller owns the cooldown that keeps this rare.
     */
    case Refresh;
}
