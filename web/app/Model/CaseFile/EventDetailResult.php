<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * Outcome of EventDetailService::fetch() together with the row as it now
 * stands - freshly re-read when something was stored, otherwise the row the
 * caller passed in, so callers never have to guess whether to reload it.
 */
final readonly class EventDetailResult
{
    public function __construct(
        public EventDetailOutcome $outcome,
        public CaseFileEvent $event,
    ) {
    }
}
