<?php declare(strict_types=1);

namespace App\Model\Integrity;


/**
 * Category of an integrity check (docs/navrh-integrita-dat.md). The two
 * categories must not be mixed in the UI - a nonzero discrepancy is a defect
 * to act on, a nonzero incompleteness is an expected state whose trend
 * matters. The third category of that design ("legitimate gaps") has no enum
 * case on purpose: it must not be reported at all, so no check may exist
 * for it.
 */
enum IntegrityCategory: string
{
    /** Must always be zero; a nonzero count is a defect. */
    case Discrepancy = 'discrepancy';

    /** Expectedly nonzero; the trend and repairability matter. */
    case Incompleteness = 'incompleteness';
}
