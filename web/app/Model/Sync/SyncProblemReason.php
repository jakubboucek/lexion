<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * Why one case file was skipped during an import. Rendered into Czech by the
 * template - the model stays language-neutral.
 */
enum SyncProblemReason
{
    /**
     * The newer of the two snapshots does not list an event the older one
     * knows. The merge is additive: an event never disappears, so this means
     * the two sides cannot be paired safely (typically upstream renumbering
     * of `poradi`, which the pairing key is built on).
     */
    case EventMissingInNewerSnapshot;

    /**
     * Two events paired by (code, poradi, owner case) carry different dates -
     * the same signature of renumbering as above, seen from the other end.
     */
    case EventDateMismatch;

    /**
     * The case file points at a codelist key the receiving side does not
     * have. Only the two hard foreign keys of the synced tables can do this -
     * the case's court and a relation's type - and either would make the
     * insert fail outright, so the whole record is left for a run that
     * happens after the codelist migration.
     */
    case UnknownCodelistKey;

    /** The record could not be read (missing or malformed fields). */
    case InvalidRecord;
}
