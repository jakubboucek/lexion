<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * The sync file cannot be processed at all: it is not ours, it was written by
 * an incompatible version, or a record is malformed. Fatal for the whole
 * import - unlike a SyncProblem, which only skips one case file.
 *
 * The message is Czech on purpose: unlike the structured SyncProblem, it is a
 * one-off sentence whose only consumer is the operator reading it on the
 * import page.
 */
class SyncException extends \RuntimeException
{
}
