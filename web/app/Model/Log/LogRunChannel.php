<?php declare(strict_types=1);

namespace App\Model\Log;


/**
 * Standard meanings of run log files - the STDOUT/STDERR analogy. The channel
 * parameters of LogRunSession accept any string, so a domain is free to open
 * channels of its own ('problems', ...); this enum only names the common ones
 * so they are spelled consistently.
 */
enum LogRunChannel: string
{
    case Out = 'out';
    case Err = 'err';
}
