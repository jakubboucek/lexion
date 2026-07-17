<?php declare(strict_types=1);

/**
 * Creates (or updates the password of) a user. Run inside the dev container
 * from the repo root:
 *
 *   docker compose exec -w /var/www/html web php bin/create-user.php <email> <nick> <password>
 *
 * If a user with the given e-mail already exists, the password and nick are
 * updated and the account is (re)activated.
 */

use App\Bootstrap;
use App\Model\UserRepository;
use Nette\Security\Passwords;

require __DIR__ . '/../web/vendor/autoload.php';

[$script, $email, $nick, $password] = $argv + [null, null, null, null];

if ($email === null || $nick === null || $password === null) {
    fwrite(STDERR, "Usage: php bin/create-user.php <email> <nick> <password>\n");
    exit(1);
}

$container = (new Bootstrap)->bootConsoleApplication();
$users = $container->getByType(UserRepository::class);
$passwords = $container->getByType(Passwords::class);

$values = [
    'email' => $email,
    'nick' => $nick,
    'password' => $passwords->hash($password),
    'is_active' => 1,
];

$existing = $users->findByEmail($email);
if ($existing !== null) {
    $users->update((int) $existing->id, $values);
    echo "Updated user: $email\n";
} else {
    $users->insert($values);
    echo "Created user: $email\n";
}
