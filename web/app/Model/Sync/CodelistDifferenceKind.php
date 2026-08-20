<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * How one codelist row differs between the file and the local database.
 * Rendered into Czech by the template - the model stays language-neutral.
 */
enum CodelistDifferenceKind
{
    /** The file has the row, the local database does not. */
    case MissingLocally;

    /** The local database has the row, the file does not. */
    case MissingInFile;

    /** Both have the row, but some of its columns differ. */
    case Differs;
}
