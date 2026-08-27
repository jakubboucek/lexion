<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Log\LogEventKind;


/**
 * Case file events in the application log. Both kinds are instant records
 * written by CaseFileSyncService around the infosoud fetch.
 */
enum CaseFileLogKind: string implements LogEventKind
{
    /** A transient infosoud failure during a case refresh - monitoring trail, never a miss. */
    case InfosoudUnavailable = 'infosoud-unavailable';

    /** A recorded lookup miss answered with a real case - a grown series, or a hole going public. */
    case MissResolved = 'miss-resolved';


    public function resource(): string
    {
        return 'case_file';
    }
}
