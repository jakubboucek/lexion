<?php declare(strict_types=1);

namespace App\Model\Integrity;

use App\Model\Log\LogEventKind;


/**
 * Integrity events in the application log. A check run is an instant record:
 * the counts are known the moment it is written, so everything goes into
 * `data` (result_data is for runs only - decision 2026-08-23).
 */
enum IntegrityLogKind: string implements LogEventKind
{
    case Check = 'check';


    public function resource(): string
    {
        return 'integrity';
    }
}
