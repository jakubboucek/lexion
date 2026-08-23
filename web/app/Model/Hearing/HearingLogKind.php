<?php declare(strict_types=1);

namespace App\Model\Hearing;

use App\Model\Log\LogEventKind;


/**
 * Hearing events in the application log: both CLI tools record themselves as
 * runs (a dry run is a run too - it reads everything a real one does).
 */
enum HearingLogKind: string implements LogEventKind
{
    /** bin/infojednani-import.php - a finished scan into the hearing tables. */
    case ScanImport = 'scan-import';

    /** bin/hearing-bind.php - linking hearings to case files (guess/confirm). */
    case Bind = 'bind';


    public function resource(): string
    {
        return 'hearing';
    }
}
