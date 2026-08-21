<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * What one export covers. The datasets are exported and uploaded separately
 * because they differ by an order of magnitude in size and because an
 * operator usually wants one of them, not everything.
 *
 * The import does not read this - it dispatches on each record's own type, so
 * a file may in principle carry anything. The dataset only decides what the
 * exporter puts in and shows up in the meta record for the operator.
 */
enum SyncDataset: string
{
    case CaseFiles = 'case_files';
    case HearingRooms = 'hearing_rooms';
    case Hearings = 'hearings';
}
