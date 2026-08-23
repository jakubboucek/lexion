<?php declare(strict_types=1);

namespace App\Model\Log;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Storage of the application log (table `log`). Deliberately thin and
 * write-only for now: entries are created by LogService and analyzed by hand
 * (Adminer) until the System UI defines what reading looks like.
 */
final readonly class LogRepository
{
    /** @var Hydrator<LogEntry> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(LogEntry::class);
    }


    /** Inserts the entity; returns it re-hydrated with the generated id. */
    public function insert(LogEntry $entry): LogEntry
    {
        $row = $this->db->table('log')->insert($this->hydrator->toData($entry));
        assert($row instanceof ActiveRow); // Selection::insert() returns ActiveRow for tables with a PK
        return $this->hydrator->fromData($row);
    }


    /** Applies a patch entity to one row - the finishing write of a run. */
    public function update(int $id, LogEntry $patch): void
    {
        $data = $this->hydrator->toData($patch);
        if ($data === []) {
            return;
        }
        $this->db->table('log')->wherePrimary($id)->update($data);
    }
}
