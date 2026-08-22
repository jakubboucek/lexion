<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Data-loss journal of case files (see JournalEntryType). Deliberately thin:
 * entries are written by CaseFileJournalService and analyzed by hand (Adminer)
 * until real occurrences show what a UI should look like.
 */
final readonly class CaseFileJournalRepository
{
    /** @var Hydrator<CaseFileJournalEntry> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(CaseFileJournalEntry::class);
    }


    /**
     * @return list<CaseFileJournalEntry>
     */
    public function findByCaseFile(int $caseFileId): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('case_file_journal')
                ->where('case_file_id', $caseFileId)
                ->order('occurred_at, id'),
        )->collectList();
    }


    /** Inserts the entity; returns it re-hydrated with the generated id and DB defaults. */
    public function insert(CaseFileJournalEntry $entry): CaseFileJournalEntry
    {
        $row = $this->db->table('case_file_journal')->insert($this->hydrator->toData($entry));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }
}
