<?php declare(strict_types=1);

namespace App\Model\Proceeding;


/**
 * How "make sure we hold this case" ended (ProceedingSyncService::ensureLoaded).
 * The homepage turns it into a form error, the case detail into a flash or an
 * error page - the decision itself is the same everywhere.
 */
enum CaseLoadOutcome
{
    /** Already on record; nothing was asked upstream. */
    case Known;

    /** Fetched from infosoud just now (and stored). */
    case Fetched;

    /** Infosoud does not know the case; we may still hold it from another source. */
    case NotFound;

    /** Infosoud is unreachable; whatever we hold is all there is. */
    case Unavailable;
}
