<?php declare(strict_types=1);

namespace App\Model\Codelist;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;


/**
 * Codelist of court registries (druhy věcí). One registry code may exist at
 * multiple court levels (one row per level); court_level NULL = level unknown
 * (code seen only in the infosoud API lov). Three registry forms live here:
 * code (display "P a Nc"), code_norm (API "P A NC"), slug (URL "panc").
 */
final readonly class RegistryRepository
{
    public function __construct(
        private Explorer $explorer,
    ) {
    }


    /** @return list<ActiveRow> */
    public function findByNorm(string $codeNorm): array
    {
        return array_values(
            $this->explorer->table('registry')->where('code_norm', strtoupper($codeNorm))->fetchAll(),
        );
    }


    /** Display form (code) for a normalized code, e.g. "P A NC" -> "P a Nc". */
    public function displayFromNorm(string $codeNorm): ?string
    {
        $row = $this->explorer->table('registry')->where('code_norm', strtoupper($codeNorm))->fetch();
        return $row !== null ? (string) $row->code : null;
    }


    /** Display form (code) for a URL slug, e.g. "panc" -> "P a Nc". Lossy reverse. */
    public function displayFromSlug(string $slug): ?string
    {
        $row = $this->explorer->table('registry')->where('slug', $slug)->fetch();
        return $row !== null ? (string) $row->code : null;
    }


    /** All distinct normalized codes – for typo suggestions. @return list<string> */
    public function findAllNorms(): array
    {
        return array_map(
            strval(...),
            $this->explorer->table('registry')->select('DISTINCT code_norm')->fetchPairs(null, 'code_norm'),
        );
    }
}
