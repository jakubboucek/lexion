<?php declare(strict_types=1);

namespace App\Model\Hearing;


/**
 * Where a single observation of a hearing came from - the value set of
 * `hearing_observation.source`. Only our own importers write the column;
 * migration 2026-08-05-00 pins the set with a CHECK so the enum and the
 * database agree on it.
 *
 * Infosoud rows are not written yet - they will come from the JED_* event
 * details (see docs/infojednani-api.md).
 */
enum ObservationSource: string
{
    case Infojednani = 'infojednani';
    case Infosoud = 'infosoud';
}
