<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Infosoud refused the request itself as invalid - either it answered with a
 * RIZENI_VALIDATION_* code, or our own InfosoudQueryPolicy knew beforehand
 * that it would.
 *
 * Distinct from its parent because the two mean opposite things to the user:
 * a failure is temporary and worth retrying, a refusal never will be. Telling
 * someone "infoSoud is currently unavailable" over a rejected query - which
 * is what happened to Nc at a regional court - sends them back to retry
 * something that cannot work.
 */
final class InfosoudRejectedException extends InfosoudApiException
{
}
