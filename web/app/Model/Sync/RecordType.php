<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * Discriminator carried by every record of a sync file.
 *
 * `Meta` is the mandatory first line, `Codelist` repeats once per compared
 * codelist, and the first record that is neither of those starts the data
 * (see SyncFormat). One `Codelist` type rather than one per codelist: the set
 * of compared codelists grows with the domains being synced, and that is data
 * about the file, not a new kind of line.
 *
 * `HearingRoom` is data, not a codelist, even though the table reads like
 * one: rooms are harvested by the scanner and curated by hand, so the two
 * environments legitimately hold different ones and they have to merge rather
 * than match (see SyncCodelistService).
 */
enum RecordType: string
{
    case Meta = 'meta';
    case Codelist = 'codelist';
    case CaseFile = 'case_file';
    case HearingRoom = 'hearing_room';
    case Hearing = 'hearing';
}
