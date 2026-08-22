<?php declare(strict_types=1);

namespace App\Model\Log;

use Nette\Http\IRequest;
use Nette\Security\User;


/**
 * Collects the automatic part of a log entry: who initiated it and from
 * where. One service for both entry points - a web request yields the URL,
 * IP, request id and the logged-in user; a CLI process yields argv and the
 * hostname. Composition instead of the FrontendLogModel-subclass pattern of
 * skradbuza: LogService mixes this in on every write.
 */
final readonly class LogContextProvider
{
    public function __construct(
        private IRequest $httpRequest,
        private User $user,
    ) {
    }


    public function userId(): ?int
    {
        if (PHP_SAPI === 'cli') {
            // No session in CLI - asking User would try to start one.
            return null;
        }
        if (!$this->user->isLoggedIn()) {
            return null;
        }
        $id = $this->user->getId();
        return is_numeric($id) ? (int) $id : null;
    }


    /** @return array<string, mixed> */
    public function context(): array
    {
        if (PHP_SAPI === 'cli') {
            return [
                'origin' => 'cli',
                'argv' => $_SERVER['argv'] ?? [],
                'hostname' => gethostname() ?: null,
            ];
        }
        $context = [
            'origin' => 'web',
            'url' => (string) $this->httpRequest->getUrl(),
            'ip' => $this->httpRequest->getRemoteAddress(),
        ];
        // Request id of the web server (Apache UNIQUE_ID), when it offers one.
        $requestId = $_SERVER['UNIQUE_ID'] ?? null;
        if (is_string($requestId)) {
            $context['requestId'] = $requestId;
        }
        return $context;
    }
}
