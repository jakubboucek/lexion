<?php declare(strict_types=1);

namespace App\Model\Codelist;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Admin-maintained mapping "registry + senate number -> court(s)". Senate
 * numbers are NOT nationally unique (verified on ISIR data), so one senate may
 * map to several courts: a single row fixes the court, multiple rows narrow
 * the candidate set. Knowingly incomplete, grows over time.
 */
final readonly class SenateRuleRepository
{
    public function __construct(
        private Explorer $db,
    ) {
    }


    /** @return list<ActiveRow> */
    public function findRules(string $registryNorm, int $senate): array
    {
        return array_values(
            $this->db->table('senate_rule')
                ->where('registry_norm', strtoupper($registryNorm))
                ->where('senate', $senate)
                ->fetchAll(),
        );
    }
}
