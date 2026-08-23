<?php declare(strict_types=1);

namespace App\Model\Hearing;

use App\Model\Log\LogEventKind;


/**
 * Hearing events in the application log. Every kind is a run owned by its
 * service (a dry run is a run too - it reads everything a real one does).
 */
enum HearingLogKind: string implements LogEventKind
{
    /** bin/infojednani-import.php - a finished scan into the hearing tables. */
    case ScanImport = 'scan-import';

    /** HearingBindService - linking hearings to case files (guess/confirm). */
    case Bind = 'bind';

    /** HearingRoomLinkService - back-filling room_id links across the table. */
    case RoomLink = 'room-link';


    public function resource(): string
    {
        return 'hearing';
    }
}
