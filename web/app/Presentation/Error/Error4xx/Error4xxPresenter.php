<?php declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use App\Presentation\Error\UserFacingError;
use Nette;
use Nette\Application\Attributes\Requires;


/**
 * Handles 4xx HTTP error responses.
 */
#[Requires(methods: '*', forward: true)]
final class Error4xxPresenter extends Nette\Application\UI\Presenter
{
    public function renderDefault(Nette\Application\BadRequestException $exception): void
    {
        // renders the appropriate error template based on the HTTP status code
        $code = $exception->getCode();
        $file = is_file($file = __DIR__ . "/$code.latte")
            ? $file
            : __DIR__ . '/4xx.latte';
        $this->template->httpCode = $code;
        if ($code === Nette\Http\IResponse::S503_ServiceUnavailable) {
            // Upstream outage, not a permanent state - tell robots to come back.
            $this->getHttpResponse()->setHeader('Retry-After', '300');
        }
        // Only messages written for the visitor are shown; anything else (the
        // framework's own wording) would describe internals - see UserFacingError.
        $this->template->message = $exception instanceof UserFacingError
            ? $exception->getMessage()
            : null;
        $this->template->setFile($file);
    }
}
