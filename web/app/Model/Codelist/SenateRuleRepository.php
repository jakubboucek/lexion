<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;


/**
 * Admin-maintained mapping "registry + senate number -> court(s)". Senate
 * numbers are NOT nationally unique (verified on ISIR data), so one senate may
 * map to several courts: a single row fixes the court, multiple rows narrow
 * the candidate set. Knowingly incomplete, grows over time.
 */
final readonly class SenateRuleRepository
{
    /** @var Hydrator<SenateRule> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(SenateRule::class);
    }


    /** @return list<SenateRule> */
    public function findRules(string $registryNorm, int $senate): array
    {
        return $this->hydrator->fromDataSet(
            $this->db->table('senate_rule')
                ->where('registry_norm', mb_strtoupper($registryNorm))
                ->where('senate', $senate),
        )->collectList();
    }
}
