<?php declare(strict_types=1);

namespace App\Model\Log;

use Nette\Utils\Json;


/**
 * JSONL channel of a run: one structured record per line.
 */
final class LogRunJsonlFile extends LogRunFile
{
    /**
     * Appends one record as a single JSON object line. A `ts` key is added by
     * the writer (and wins over a caller-supplied one), so every line carries
     * the same timestamp form as the text channels.
     *
     * @param array<mixed>|\JsonSerializable $record
     */
    public function write(array|\JsonSerializable $record): void
    {
        if ($record instanceof \JsonSerializable) {
            $record = $record->jsonSerialize();
            if (!is_array($record)) {
                throw new \InvalidArgumentException('A JSONL record must serialize into an array.');
            }
        }
        $this->writeRaw(Json::encode(['ts' => self::now()] + $record) . "\n");
    }
}
