<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Resolves any court code seen in upstream data (our codelist codes, ISIR
 * prefixes, infosoud internal org codes like NSJIMBM) to our court kod.
 */
final readonly class CourtCodeResolver
{
    public function __construct(
        private CourtRepository $courts,
        private CourtPrefixRepository $prefixes,
    ) {
    }


    public function resolveKod(string $code): ?string
    {
        $code = strtoupper($code);
        if ($code === '') {
            return null;
        }
        if ($this->courts->getByKod($code) !== null) {
            return $code;
        }
        $prefix = $this->prefixes->getByPrefix($code);
        return $prefix !== null ? (string) $prefix->court_kod : null;
    }
}
