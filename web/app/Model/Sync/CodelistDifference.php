<?php declare(strict_types=1);

namespace App\Model\Sync;


/** One difference found between the file's codelists and the local ones. */
final readonly class CodelistDifference
{
    public function __construct(
        /** Codelist (table) name, e.g. `court`. */
        public string $codelist,
        /** Natural key of the row within the codelist, e.g. `OSSEMOP`. */
        public string $key,
        public CodelistDifferenceKind $kind,
    ) {
    }
}
