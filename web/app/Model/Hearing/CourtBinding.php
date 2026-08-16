<?php declare(strict_types=1);

namespace App\Model\Hearing;


/**
 * How strongly a hearing is believed to belong to the case it links to - the
 * value set of `hearing.court_binding` (enforced by a CHECK in migration
 * 2026-07-26-00).
 *
 * infoJednani only tells us the court of the ROOM, which is a candidate home
 * court, so everything imported starts as VenueGuess; Confirmed means the
 * hearing was cross-checked against an infoSoud JED_* event detail of the case
 * (see bin/hearing-bind.php). A 'refuted' state does not exist yet.
 */
enum CourtBinding: string
{
    case VenueGuess = 'venue_guess';
    case Confirmed = 'confirmed';
}
