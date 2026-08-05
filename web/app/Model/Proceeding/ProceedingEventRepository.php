<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Timeline events of a case file (see docs/analyza-udalosti.md). Rows are
 * maintained by ProceedingProjectionService; URLs and internal references use
 * the surrogate id only - event_order (upstream "poradi") serves sync pairing
 * and display ordering.
 *
 * The class name still says Proceeding (renamed with the rest of the domain in
 * one wave); what it returns is already CaseFileEvent.
 */
final readonly class ProceedingEventRepository
{
    /** @var Hydrator<CaseFileEvent> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(CaseFileEvent::class);
    }


    /**
     * Timeline order: date first (poradi is insertion order, not chronology),
     * own events before foreign ones within a day (a foreign poradi comes from
     * another sequence), then poradi.
     *
     * @return list<CaseFileEvent>
     */
    public function findByCaseFile(int $caseFileId): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('proceeding_event')
                ->where('proceeding_id', $caseFileId)
                ->order('event_date, (ref_court_kod IS NOT NULL), event_order'),
        )->collectList();
    }


    /**
     * @return list<CaseFileEvent>
     */
    public function findByCaseFileAndSource(int $caseFileId, string $source): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('proceeding_event')
                ->where('proceeding_id', $caseFileId)
                ->where('source', $source),
        )->collectList();
    }


    public function getById(int $id): ?CaseFileEvent
    {
        $row = $this->db->table('proceeding_event')->get($id);
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(CaseFileEvent $event): CaseFileEvent
    {
        $row = $this->db->table('proceeding_event')->insert($this->hydrator->toData($event));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /** Patches the row with the initialized properties of $changes. */
    public function update(int $id, CaseFileEvent $changes): void
    {
        $this->db->table('proceeding_event')->wherePrimary($id)->update($this->hydrator->toData($changes));
    }


    public function delete(int $id): void
    {
        $this->db->table('proceeding_event')->wherePrimary($id)->delete();
    }
}
