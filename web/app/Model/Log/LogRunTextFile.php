<?php declare(strict_types=1);

namespace App\Model\Log;


/**
 * Text channel of a run: timestamped, greppable lines.
 */
final class LogRunTextFile extends LogRunFile
{
    public function writeLine(string $line): void
    {
        $this->writeRaw('[' . self::now() . '] ' . $line . "\n");
    }
}
