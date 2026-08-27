<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Unexpected infosoud API failure (network error, changed schema, HTTP 5xx).
 * "Case not found" is NOT an exception - the client returns null.
 *
 * A request infosoud refuses as invalid is not a failure of the service, so
 * it raises InfosoudRejectedException instead - callers that only care that
 * no data arrived can keep catching this one.
 */
class InfosoudApiException extends \RuntimeException
{
}
