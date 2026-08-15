<?php declare(strict_types=1);

namespace App\Model\User;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Users that may log into the app. Passwords are stored as hashes (see
 * App\Core\Authenticator); this repository never hashes itself.
 *
 * First repository converted to typed entities: nothing but User instances
 * leaves it, and writes take entities too - a partially filled entity
 * extracts to exactly the touched columns, so `update()` is a natural patch
 * (see docs/architektura.md).
 */
final readonly class UserRepository
{
    /** @var Hydrator<User> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(User::class);
    }


    /** All users, ordered by nick. @return list<User> */
    public function findAll(): array
    {
        return $this->hydrator
            ->fromDataSet($this->db->table('user')->order('nick'))
            ->collectList();
    }


    public function getById(int $id): ?User
    {
        return $this->hydrate($this->db->table('user')->get($id));
    }


    public function findByEmail(string $email): ?User
    {
        return $this->hydrate($this->db->table('user')->where('email', $email)->fetch());
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(User $user): User
    {
        $row = $this->db->table('user')->insert($this->hydrator->toData($user));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /**
     * Patches the row with the initialized properties of $changes - an entity
     * with only the touched properties set produces exactly those columns.
     */
    public function update(int $id, User $changes): void
    {
        $this->db->table('user')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }


    public function delete(int $id): void
    {
        $this->db->table('user')->wherePrimary($id)->delete();
    }


    private function hydrate(mixed $row): ?User
    {
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }
}
