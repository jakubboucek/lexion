<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * How "make sure we hold this case" ended (CaseFileSyncService::ensureLoaded).
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

    /**
     * Infosoud refuses to answer for this identity at all (an unqueryable
     * registry at this court level). Unlike Unavailable this never passes -
     * retrying is pointless and the user must be told something else.
     */
    case Rejected;
}
