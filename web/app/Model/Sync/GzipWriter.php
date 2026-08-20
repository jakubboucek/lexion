<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * Streaming gzip wrapper around an output sink.
 *
 * Sync files are dense JSON with a raw JSON payload escaped inside them, so
 * they compress by roughly an order of magnitude - which is what keeps an
 * export part under the upload limit of the receiving side. The compression
 * is streamed rather than applied to a finished string: the export never
 * holds the whole part in memory, and neither should this.
 *
 * Nothing on the reading side depends on it: the import opens files through
 * zlib, which reads plain and gzipped input alike.
 */
final class GzipWriter
{
    private \DeflateContext $context;

    /** @var \Closure(string): void */
    private \Closure $output;


    /** @param callable(string): void $output */
    public function __construct(callable $output)
    {
        $this->output = $output(...);
        $this->context = deflate_init(\ZLIB_ENCODING_GZIP);
    }


    public function write(string $chunk): void
    {
        // zlib emits nothing until it has enough to compress; an empty result
        // is the normal case, not an error.
        $compressed = deflate_add($this->context, $chunk, \ZLIB_NO_FLUSH);
        if ($compressed !== '') {
            ($this->output)($compressed);
        }
    }


    /** Flushes the remainder and closes the gzip member. Call exactly once. */
    public function finish(): void
    {
        $compressed = deflate_add($this->context, '', \ZLIB_FINISH);
        if ($compressed !== '') {
            ($this->output)($compressed);
        }
    }
}
