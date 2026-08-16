<?php declare(strict_types=1);

namespace App\Presentation\Error;

use Nette\Application\BadRequestException;


/**
 * A 4xx whose message is written for the visitor - "Řízení neevidujeme.",
 * not "Cannot load presenter 'Foo'".
 *
 * The distinction matters because the error templates print the message
 * (tech-debt ST-2) and they may only print ours: a plain BadRequestException
 * can just as well come from the framework, whose messages describe internals
 * ("No route for HTTP request.", the presenter class that failed to load).
 * Unmarked errors therefore keep the generic wording - fail closed.
 *
 * Presenters throw this instead of calling $this->error(); the throw is also
 * what tells static analysis the code path ends here.
 */
final class UserFacingError extends BadRequestException
{
}
