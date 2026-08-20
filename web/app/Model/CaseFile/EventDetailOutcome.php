<?php declare(strict_types=1);

namespace App\Model\Proceeding;


/**
 * How a lazy fetch of one event's upstream detail ended. The caller decides
 * how to say it - the web shows a flash, the CLI prints a line - but the
 * decision itself belongs to EventDetailService.
 */
enum EventDetailOutcome
{
    /** Detail downloaded and stored on the row. */
    case Fetched;

    /** Upstream has no detail for the record; remembered so we stop asking. */
    case NoDetail;

    /** Nothing was even asked: the row was already fetched before. */
    case AlreadyFetched;

    /** The record carries no upstream address (no poradi, or unknown owner court). */
    case NotAddressable;

    /** Upstream is unreachable or answered with an error; nothing stored. */
    case Unavailable;

    /**
     * The detail describes a different record than the row does - upstream
     * renumbered its events under us (see docs/analyza-udalosti.md). Nothing
     * is stored; the case needs a refresh to rebuild the projection.
     */
    case IntegrityBroken;
}
