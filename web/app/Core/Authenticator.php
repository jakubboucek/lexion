<?php declare(strict_types=1);

namespace App\Core;

use App\Model\UserRepository;
use Nette\Security\AuthenticationException;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;


/**
 * Verifies user credentials (e-mail + password) against the user table.
 * Wired as the application authenticator in config/services.neon.
 */
final readonly class Authenticator implements \Nette\Security\Authenticator
{
    public function __construct(
        private UserRepository $users,
        private Passwords $passwords,
    ) {
    }


    public function authenticate(string $username, string $password): IIdentity
    {
        $row = $this->users->findByEmail($username);
        if ($row === null) {
            throw new AuthenticationException('Neznámý e-mail.', self::IdentityNotFound);
        }

        if (!$row->is_active) {
            throw new AuthenticationException('Účet je deaktivovaný.', self::NotApproved);
        }

        if (!$this->passwords->verify($password, $row->password)) {
            throw new AuthenticationException('Chybné heslo.', self::InvalidCredential);
        }

        // Transparently upgrade the stored hash if the algorithm parameters changed.
        if ($this->passwords->needsRehash($row->password)) {
            $this->users->update((int) $row->id, ['password' => $this->passwords->hash($password)]);
        }

        return new SimpleIdentity($row->id, [], [
            'nick' => $row->nick,
            'email' => $row->email,
        ]);
    }
}
