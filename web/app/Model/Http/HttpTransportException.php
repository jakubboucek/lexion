<?php declare(strict_types=1);

namespace App\Model\Http;


/** Network-level failure (DNS, connect, timeout) after all retries. */
final class HttpTransportException extends \RuntimeException
{
}
