<?php declare(strict_types=1);

namespace App\Model\User;

use JakubBoucek\Hydrator\Entity;


/**
 * A user who may log into the app (table `user`). The password column holds
 * a hash - hashing itself belongs to App\Core\Authenticator, never here.
 *
 * The first entity of the typed-data refactoring (see
 * docs/entity-refactoring.md): plain typed public properties, no constructor
 * and no attributes - the conventional camelCase <-> snake_case mapping
 * covers every column.
 */
class User implements Entity
{
    public int $id;
    public string $email;
    public string $password;
    public string $nick;
    public bool $isActive;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
}
