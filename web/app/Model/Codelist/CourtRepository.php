<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Codelist of courts (see migration 2026-07-18-00). Read-only in practice -
 * rows change by migration only. The public API is deliberately unchanged by
 * the entity conversion: its internals are due to be swapped for a cached
 * snapshot (see docs/analyza-ciselniky.md), so point queries here are a
 * knowingly temporary state.
 */
final readonly class CourtRepository
{
    /** @var Hydrator<Court> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(Court::class);
    }


    /**
     * All courts, higher instances first, then by name. The ordering is the
     * database's (collation-aware), never rebuilt in PHP.
     *
     * @return list<Court>
     */
    public function findAll(): array
    {
        return $this->hydrator
            ->fromDataSet($this->db->table('court')->order('level DESC, name'))
            ->collectList();
    }


    public function getByKod(string $kod): ?Court
    {
        return $this->hydrate($this->db->table('court')->get($kod));
    }


    public function getBySlug(string $slug): ?Court
    {
        return $this->hydrate($this->db->table('court')->where('slug', $slug)->fetch());
    }


    /**
     * Court by its exact name. Infosoud names courts in free-text attributes
     * (ODVOL_SOUD = "Městský soud Praha") with the same wording as the codelist,
     * which is the only way to resolve the court of a referenced case there.
     */
    public function getByName(string $name): ?Court
    {
        return $this->hydrate($this->db->table('court')->where('name', trim($name))->fetch());
    }


    /**
     * Courts of the given levels, in the same order as findAll().
     *
     * @param list<CourtLevel> $levels
     * @return list<Court>
     */
    public function findByLevels(array $levels): array
    {
        $values = array_map(static fn(CourtLevel $level): string => $level->value, $levels);
        return $this->hydrator->fromDataSet(
            $this->db->table('court')->where('level', $values)->order('level DESC, name'),
        )->collectList();
    }


    private function hydrate(mixed $row): ?Court
    {
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }
}
