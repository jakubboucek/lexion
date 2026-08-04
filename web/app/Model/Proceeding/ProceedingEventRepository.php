<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Timeline events of a proceeding (see docs/analyza-udalosti.md). Thin
 * Selection API; rows are maintained by ProceedingProjectionService. URLs and
 * internal references use the surrogate id only - event_order (upstream
 * "poradi") serves sync pairing and display ordering.
 */
final readonly class ProceedingEventRepository
{
    public function __construct(
        private Explorer $db,
    ) {
    }


    /**
     * Timeline order: date first (poradi is insertion order, not chronology),
     * own events before foreign ones within a day (a foreign poradi comes from
     * another sequence), then poradi.
     *
     * @return list<ActiveRow>
     */
    public function findByProceeding(int $proceedingId): array
    {
        return array_values(
            $this->db->table('proceeding_event')
                ->where('proceeding_id', $proceedingId)
                ->order('event_date, (ref_court_kod IS NOT NULL), event_order')
                ->fetchAll(),
        );
    }


    /**
     * @return list<ActiveRow>
     */
    public function findByProceedingAndSource(int $proceedingId, string $source): array
    {
        return array_values(
            $this->db->table('proceeding_event')
                ->where('proceeding_id', $proceedingId)
                ->where('source', $source)
                ->fetchAll(),
        );
    }


    public function getById(int $id): ?ActiveRow
    {
        return $this->db->table('proceeding_event')->get($id) ?: null;
    }


    public function insert(array $data): ActiveRow
    {
        $row = $this->db->table('proceeding_event')->insert($data);
        assert($row instanceof ActiveRow);
        return $row;
    }


    public function update(int $id, array $data): void
    {
        $this->db->table('proceeding_event')->wherePrimary($id)->update($data);
    }


    public function delete(int $id): void
    {
        $this->db->table('proceeding_event')->wherePrimary($id)->delete();
    }
}
