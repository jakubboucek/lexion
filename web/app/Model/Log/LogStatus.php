<?php declare(strict_types=1);

namespace App\Model\Log;


/**
 * State of a log entry, promise-style (table `log`; the DB holds the same set
 * in the column ENUM - extend it by APPENDING to that list). An instant
 * record is born Ok or Failed and never changes; a run starts as Pending and
 * finishes into Ok or Failed. Pending is the only marker of "running or
 * crashed" - telling those two apart is deliberately left to the reader for
 * now (see docs/logovani.md).
 */
enum LogStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Failed = 'failed';
}
