<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * One planned update of an existing event row (part of
 * CaseFileProjectionPlan): the current row, the patch to apply, and whether
 * applying it destroys a fetched detail (a moved date on the same pairing key
 * smells like upstream renumbering - the cached detail may belong to another
 * event, so the plan wipes it).
 */
final readonly class CaseFileEventUpdate
{
    public function __construct(
        public CaseFileEvent $current,
        public CaseFileEvent $changes,
        public bool $dropsDetail,
    ) {
    }
}
