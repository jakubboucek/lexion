<?php declare(strict_types=1);

namespace App\Model\Codelist;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Admin-maintained mapping "registry + senate number -> court" (e.g. senate
 * 60 of the INS registry belongs to the Regional Court in Prague). Knowingly
 * incomplete, grows over time.
 */
final readonly class SenateRuleRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    public function findRule(string $registryNorm, int $senate): ?ActiveRow
    {
        return $this->explorer->table('senate_rule')
            ->where('registry_norm', strtoupper($registryNorm))
            ->where('senate', $senate)
            ->fetch() ?: null;
    }
}
