<?php declare(strict_types=1);

namespace App\Model\Log;

use JakubBoucek\Hydrator\Struct\JsonObject;


/**
 * A running run: write into its files, then finish() exactly once - callers
 * are encouraged to put finish() in a `finally` where the code structure
 * allows. finish() closes the files; an empty one is deleted and NULLed in
 * the `files` map, so the row still shows the channel existed. A second
 * finish() is silently ignored.
 */
final class LogRun
{
    private bool $finished = false;


    /**
     * @param array<string, LogRunFile> $files
     * @internal created by LogRunSession::start()
     */
    public function __construct(
        private readonly LogRepository $repository,
        public readonly int $id,
        private readonly array $files,
    ) {
    }


    /**
     * @param array<mixed>|null $resultData summary payload of the outcome (counts, a report...)
     */
    public function finish(
        LogStatus $status,
        ?string $result = null,
        ?string $message = null,
        ?array $resultData = null,
    ): void
    {
        if ($this->finished) {
            return;
        }
        if ($status === LogStatus::Pending) {
            throw new \InvalidArgumentException('A run cannot finish as pending.');
        }
        $this->finished = true;

        $fileNames = [];
        foreach ($this->files as $meaning => $file) {
            if ($file->close()) {
                $fileNames[$meaning] = $file->fileName;
            } else {
                @unlink($file->path);
                $fileNames[$meaning] = null;
            }
        }

        $patch = new LogEntry;
        $patch->status = $status;
        $patch->result = $result;
        $patch->message = $message;
        $patch->resultData = JsonObject::fromArray($resultData ?? []);
        // JsonObject keeps the null values - the "channel existed but stayed
        // empty" information must survive the write.
        $patch->files = JsonObject::fromArray($fileNames);
        $patch->finishedAt = new \DateTimeImmutable;
        $this->repository->update($this->id, $patch);
    }
}
