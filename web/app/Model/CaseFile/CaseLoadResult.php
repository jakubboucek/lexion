<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * Outcome of CaseFileSyncService::ensureLoaded() with the case file we hold
 * afterwards - which can be non-null even for NotFound or Unavailable, when we
 * already had the case from another source.
 */
final readonly class CaseLoadResult
{
    public function __construct(
        public CaseLoadOutcome $outcome,
        public ?CaseFile $case,
    ) {
    }
}
