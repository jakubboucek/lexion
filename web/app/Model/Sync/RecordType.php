<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * Discriminator carried by every record of a sync file. `Meta` and
 * `Codelists` are the mandatory first two lines (see SyncFormat); the rest
 * are data records - `CaseFile` today, hearings in the next phase.
 */
enum RecordType: string
{
    case Meta = 'meta';
    case Codelists = 'codelists';
    case CaseFile = 'case_file';
}
