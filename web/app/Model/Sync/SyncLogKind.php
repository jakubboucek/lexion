<?php declare(strict_types=1);

namespace App\Model\Sync;

use App\Model\Log\LogEventKind;


/**
 * Sync events in the application log: an import is a run (progress and
 * skipped records go to its files), an export is an instant record.
 */
enum SyncLogKind: string implements LogEventKind
{
    case Import = 'import';
    case Export = 'export';


    public function resource(): string
    {
        return 'sync';
    }
}
