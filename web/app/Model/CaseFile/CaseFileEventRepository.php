<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Timeline events of a case file (see docs/analyza-udalosti.md). Rows are
 * maintained by CaseFileProjectionService; URLs and internal references use
 * the surrogate id only - event_order (upstream "poradi") serves sync pairing
 * and display ordering.
 */
final readonly class CaseFileEventRepository
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
            $this->db->table('case_file_event')
                ->where('case_file_id', $caseFileId)
                ->order('event_date, (ref_court_kod IS NOT NULL), event_order'),
        )->collectList();
    }


    /**
     * Timeline events of several case files at once, grouped by case file id
     * and ordered as above - one query where a list view would otherwise ask
     * per row (dashboard subjects, related-case enrichment).
     *
     * @param list<int> $caseFileIds
     * @return array<int, list<CaseFileEvent>> case files without events are absent
     */
    public function findByCaseFiles(array $caseFileIds): array
    {
        if ($caseFileIds === []) {
            return [];
        }
        $grouped = [];
        $rows = $this->hydrator->fromDataSet(
            $this->db->table('case_file_event')
                ->where('case_file_id', $caseFileIds)
                ->order('case_file_id, event_date, (ref_court_kod IS NOT NULL), event_order'),
        );
        foreach ($rows as $event) {
            $grouped[$event->caseFileId][] = $event;
        }
        return $grouped;
    }


    /**
     * @return list<CaseFileEvent>
     */
    public function findByCaseFileAndSource(int $caseFileId, string $source): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('case_file_event')
                ->where('case_file_id', $caseFileId)
                ->where('source', $source),
        )->collectList();
    }


    public function getById(int $id): ?CaseFileEvent
    {
        $row = $this->db->table('case_file_event')->get($id);
        return $row instanceof ActiveRow ? $this->hydrator->fromData($row) : null;
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(CaseFileEvent $event): CaseFileEvent
    {
        $row = $this->db->table('case_file_event')->insert($this->hydrator->toData($event));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /**
     * Patches the row with the initialized properties of $changes. An entity
     * with nothing set is a no-op, so a caller that collects differences can
     * hand over whatever it gathered without checking first: the extraction
     * happens anyway, so an empty payload is the cheapest possible guard - it
     * needs no introspection and reads no field names or values.
     */
    public function update(int $id, CaseFileEvent $changes): void
    {
        $data = $this->hydrator->toData($changes);
        if ($data === []) {
            return;
        }
        $this->db->table('case_file_event')->wherePrimary($id)->update($data);
    }


    public function delete(int $id): void
    {
        $this->db->table('case_file_event')->wherePrimary($id)->delete();
    }
}
