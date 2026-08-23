<?php declare(strict_types=1);

namespace App\Model\Sync;

use Nette;


/**
 * Wire contract of a sync file: JSONL, one record per line, every record
 * carrying a `type` discriminator.
 *
 * The order of the lines is part of the contract. The first line is always a
 * `meta` record; then comes one `codelist` record per compared codelist; the
 * first record that is neither starts the data. The reader therefore settles
 * compatibility and codelist consistency before it touches the database, and
 * processes the data one record at a time, so neither side ever holds the
 * whole file in memory.
 *
 * The codelists travel as one record each rather than one record for all of
 * them: a single line held every codelist of the project at once, which made
 * it tens of kilobytes long and unreadable to anyone opening the file.
 *
 * `Version` is the compatibility gate: bump it whenever the shape of any
 * record changes, so an export can never be fed to an import that would read
 * it differently. There is deliberately no backwards-compatibility window -
 * both ends of a sync are our own deploys, and a refused file is a far
 * cheaper failure than a silently misread one.
 */
final class SyncFormat
{
    use Nette\StaticClass;

    /** Marker of our own format; guards against feeding in a foreign file. */
    public const string Format = 'lexion-sync';

    /**
     * Incompatible-change counter - see the class docblock.
     * 2: event records carry parentOrder (materialized nested hearing terms).
     */
    public const int Version = 2;

    /**
     * Exports are gzipped - the format is dense JSON around an escaped JSON
     * payload, so it shrinks by roughly an order of magnitude, which is what
     * decides whether a part fits the receiving side's upload limit. The
     * import reads plain and gzipped files alike, so an uncompressed file is
     * still a valid input.
     */
    public const string ContentType = 'application/gzip';

    /**
     * Default number of case files per part - see SyncExportService::parts().
     * Gzip shrinks a part by roughly 15x, so this is a few hundred kilobytes;
     * the operator raises it when the receiving side allows a bigger upload.
     */
    public const int DefaultPartSize = 5000;


    /** File name of one part, e.g. lexion-sync-hearings-20260820-1204-1z7.jsonl.gz */
    public static function fileName(
        SyncDataset $dataset,
        \DateTimeInterface $generatedAt,
        int $part,
        int $parts,
    ): string
    {
        return sprintf(
            '%s-%s-%s-%dz%d.jsonl.gz',
            self::Format,
            str_replace('_', '-', $dataset->value),
            $generatedAt->format('Ymd-Hi'),
            $part,
            $parts,
        );
    }
}
