<?php declare(strict_types=1);

namespace App\Core;

use App\Model\User\User;
use App\Model\User\UserRepository;
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
        $user = $this->users->findByEmail($username);
        if ($user === null) {
            throw new AuthenticationException('Neznámý e-mail.', self::IdentityNotFound);
        }

        if (!$user->isActive) {
            throw new AuthenticationException('Účet je deaktivovaný.', self::NotApproved);
        }

        if (!$this->passwords->verify($password, $user->password)) {
            throw new AuthenticationException('Chybné heslo.', self::InvalidCredential);
        }

        // Transparently upgrade the stored hash if the algorithm parameters
        // changed; only the touched property is extracted, so this updates
        // the password column alone.
        if ($this->passwords->needsRehash($user->password)) {
            $rehash = new User();
            $rehash->password = $this->passwords->hash($password);
            $this->users->update($user->id, $rehash);
        }

        return new SimpleIdentity($user->id, [], [
            'nick' => $user->nick,
            'email' => $user->email,
        ]);
    }
}
