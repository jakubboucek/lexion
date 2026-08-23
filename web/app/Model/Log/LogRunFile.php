<?php declare(strict_types=1);

namespace App\Model\Log;


/**
 * One file of a run. Writers are handed out by LogRunBuilder before the run
 * exists, so they accept input only between LogRunBuilder::start() and
 * LogRun::finish(): a line must never precede the DB row of its run, and a
 * finished run must never grow.
 *
 * Writes go through PHP's default stream buffering on purpose - no manual
 * flushing. Every PHP-level ending (clean finish, uncaught exception, fatal
 * error) closes the stream and writes the buffer out; only an external kill
 * can cost the buffer tail, and that is an accepted trade
 * (see docs/logovani.md).
 */
abstract class LogRunFile
{
    /** @var resource|null */
    private $handle = null;

    private bool $wrote = false;
    private bool $closed = false;


    /**
     * @internal created by LogRunBuilder
     */
    public function __construct(
        /** Filename relative to the log directory, as stored in the `files` map. */
        public readonly string $fileName,
        /** Absolute path; also where a consumer can point the operator to. */
        public readonly string $path,
    ) {
    }


    /** @internal opened by LogRunBuilder::start() */
    public function open(): void
    {
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open run log file '{$this->path}'.");
        }
        $this->handle = $handle;
    }


    /**
     * Closes the stream and reports whether anything was written - LogRun
     * deletes the file and NULLs it in the `files` map when not.
     *
     * @internal called by LogRun::finish()
     */
    public function close(): bool
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        $this->closed = true;
        return $this->wrote;
    }


    protected function writeRaw(string $line): void
    {
        if ($this->closed) {
            throw new \LogicException('The run is already finished, its log files accept no more input.');
        }
        if ($this->handle === null) {
            throw new \LogicException('The run has not been started yet - call LogRunBuilder::start() before writing.');
        }
        fwrite($this->handle, $line);
        $this->wrote = true;
    }


    protected static function now(): string
    {
        return new \DateTimeImmutable()->format('Y-m-d H:i:s.v');
    }


    public function __destruct()
    {
        // A run abandoned without finish() only gets its stream closed (the
        // buffer is written out); the DB row stays pending - automatic crash
        // detection is deliberately not built yet.
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
