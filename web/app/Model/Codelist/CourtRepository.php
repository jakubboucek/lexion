<?php declare(strict_types=1);

namespace App\Model\Codelist;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


/**
 * Codelist of courts (see migration 2026-07-18-00). Read-mostly; admin edits
 * come later.
 */
final readonly class CourtRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    public function findAll(): Selection
    {
        return $this->explorer->table('court')->order('level DESC, name');
    }


    public function getByKod(string $kod): ?ActiveRow
    {
        return $this->explorer->table('court')->get($kod);
    }


    /** @param list<CourtLevel> $levels */
    public function findByLevels(array $levels): Selection
    {
        $values = array_map(static fn(CourtLevel $l) => $l->value, $levels);
        return $this->explorer->table('court')->where('level', $values)->order('level DESC, name');
    }
}
