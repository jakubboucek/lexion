<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


/**
 * Users that may log into the app. Passwords are stored as hashes (see
 * App\Core\Authenticator); this repository never hashes itself.
 */
final readonly class UserRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    /** All users, ordered by nick – for a future user listing. */
    public function findAll(): Selection
    {
        return $this->explorer->table('user')->order('nick');
    }


    public function getById(int $id): ?ActiveRow
    {
        return $this->explorer->table('user')->get($id);
    }


    public function findByEmail(string $email): ?ActiveRow
    {
        return $this->explorer->table('user')->where('email', $email)->fetch() ?: null;
    }


    public function insert(array $data): ActiveRow
    {
        $row = $this->explorer->table('user')->insert($data);
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $row;
    }


    public function update(int $id, array $data): void
    {
        $this->explorer->table('user')->wherePrimary($id)->update($data);
    }


    public function delete(int $id): void
    {
        $this->explorer->table('user')->wherePrimary($id)->delete();
    }
}
