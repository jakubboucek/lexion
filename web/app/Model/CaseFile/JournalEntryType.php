<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * What kind of data-loss anomaly a journal entry records (table
 * `case_file_journal`; the DB holds the same set in a CHECK). The journal
 * records facts, not interpretations - "an event row disappeared", never
 * "upstream renumbered" - so the types name the operation, not its suspected
 * cause.
 */
enum JournalEntryType: string
{
    /**
     * A projection run destroyed data: dropped event rows (their ids are in
     * URLs and may carry a fetched detail), wiped a cached detail on a moved
     * event date, or removed relations missing from the fresh payload.
     * One entry covers the whole run; the individual operations are listed
     * in `context`.
     */
    case ProjectionDataLoss = 'projection_data_loss';

    /**
     * A fetched event detail described a different record than the row it was
     * fetched for (EventDetailOutcome::IntegrityBroken) and was refused. The
     * refused payload - the only authentic evidence of a renumbered case we
     * will ever get - is kept in `context`.
     */
    case EventDetailRejected = 'event_detail_rejected';

    /**
     * An upstream case response was refused instead of stored (today: the
     * echoed rocnik does not match the requested year - the "2098 answers
     * with the 1998 case" trap). The paid-for payload is kept in `context`.
     */
    case CaseResponseRejected = 'case_response_rejected';

    /**
     * A stored raw payload could not be decoded (StoredJsonException) - the
     * projection is frozen on its previous content until repaired. Nothing
     * was lost by this entry's operation, but the state is recorded before
     * any repair overwrites the broken payload.
     */
    case PayloadUnreadable = 'payload_unreadable';
}
