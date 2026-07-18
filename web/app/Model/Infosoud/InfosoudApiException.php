<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Unexpected infosoud API failure (network error, validation error, changed
 * schema). "Case not found" is NOT an exception - the client returns null.
 */
final class InfosoudApiException extends \RuntimeException
{
}
