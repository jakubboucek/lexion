<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * A raw JSON column of a case file cannot be read back (see StoredJson).
 * Always a data-integrity problem on our side, never an upstream quirk.
 */
final class StoredJsonException extends \RuntimeException
{
}
