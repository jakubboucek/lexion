<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * The codelists of the two environments differ, so the import refuses to run.
 *
 * This is deliberately fail-closed. We have no answer yet for what a codelist
 * change means for the data hanging off it (a courtroom that disappears while
 * hearings still point at it, a registry row that gained a different slug),
 * so a difference means the environments have drifted and must be aligned by
 * a migration first - not papered over by an import.
 */
final class CodelistMismatchException extends SyncException
{
    /** @param list<CodelistDifference> $differences */
    public function __construct(
        public readonly array $differences,
    ) {
        parent::__construct('Codelists of the two environments differ.');
    }
}
