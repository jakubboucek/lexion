<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * Deterministic outcome of a failed case lookup (table `case_lookup_miss`,
 * CHECK-backed). Only outcomes that would repeat on a retry belong here -
 * transient failures are logged, never recorded as misses.
 */
enum CaseLookupOutcome: string
{
    /** The source answered "no such case" (infosoud: HTTP 400 not found). */
    case NotFound = 'not_found';

    /** The source refused the query itself as invalid (e.g. Nc at a regional court). */
    case Rejected = 'rejected';

    /** The response described a different vintage - the pre-2000 two-digit year trap. */
    case YearMismatch = 'year_mismatch';
}
