<?php declare(strict_types=1);

namespace App\Model\Spisovka;

use App\Model\Codelist\CourtLevel;
use App\Model\Codelist\CourtPrefixRepository;
use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\RegistryRepository;
use App\Model\Codelist\SenateRuleRepository;


/**
 * Validates a parsed file number against the codelists and tries to determine
 * the court. Detection is a pipeline of independent rules, each narrowing the
 * candidate set (court prefix > senate rule > registry level); designed to be
 * extended with further rules over time.
 */
final readonly class SpisovkaResolver
{
    private const int MaxSuggestionDistance = 2;

    public function __construct(
        private RegistryRepository $registries,
        private CourtRepository $courts,
        private CourtPrefixRepository $prefixes,
        private SenateRuleRepository $senateRules,
    ) {
    }


    public function resolve(Spisovka $spisovka): SpisovkaResolution
    {
        $errors = [];
        $warnings = [];
        $suggestions = [];

        // 1. Registry against the codelist.
        $registryRows = $this->registries->findByNorm($spisovka->registryNorm());
        $registryLevels = [];
        $registryDescription = null;
        foreach ($registryRows as $row) {
            if ($row->court_level !== null) {
                $registryLevels[] = CourtLevel::from($row->court_level);
            }
            $registryDescription ??= $row->description;
        }
        if ($registryRows === []) {
            $suggestions = $this->suggestRegistry($spisovka->registryNorm());
            $errors[] = $suggestions === []
                ? sprintf('Rejstřík „%s“ neexistuje.', $spisovka->registry)
                : sprintf('Rejstřík „%s“ neexistuje – mysleli jste „%s“?', $spisovka->registry, implode('", „', $suggestions));
        }

        // 2. Court detection pipeline.
        $fixedCourtKod = null;
        $fixedCourtReason = null;
        $candidates = [];

        if ($spisovka->courtPrefix !== null) {
            $prefixRow = $this->prefixes->getByPrefix($spisovka->courtPrefix);
            if ($prefixRow === null) {
                $errors[] = sprintf('Neznámá zkratka soudu „%s“.', $spisovka->courtPrefix);
            } else {
                $fixedCourtKod = $prefixRow->court_kod;
                $fixedCourtReason = sprintf('podle zkratky soudu „%s“', $spisovka->courtPrefix);
            }
        }

        if ($fixedCourtKod === null) {
            $rules = $this->senateRules->findRules($spisovka->registryNorm(), $spisovka->senate);
            $ruleKods = array_values(array_unique(array_map(static fn($rule) => (string) $rule->court_kod, $rules)));
            if (count($ruleKods) === 1) {
                $fixedCourtKod = $ruleKods[0];
                $fixedCourtReason = sprintf('podle senátu %d %s', $spisovka->senate, $spisovka->registry);
            } elseif ($ruleKods !== []) {
                // Senate numbers are not nationally unique - narrow, don't fix.
                $candidates = $ruleKods;
            }
        }

        if ($fixedCourtKod === null && $candidates === [] && $registryLevels !== []) {
            $candidateRows = $this->courts->findByLevels($registryLevels);
            $candidates = array_map(static fn($row) => (string) $row->kod, array_values($candidateRows->fetchAll()));
            if (count($candidates) === 1) {
                $fixedCourtKod = $candidates[0];
                $fixedCourtReason = sprintf(
                    'rejstřík „%s“ vede jen %s',
                    $spisovka->registry,
                    $registryLevels[0]->label(),
                );
                $candidates = [];
            }
        }

        // 3. Consistency check: a fixed court should match the registry levels.
        if ($fixedCourtKod !== null && $registryLevels !== []) {
            $court = $this->courts->getByKod($fixedCourtKod);
            if ($court !== null && !in_array(CourtLevel::from($court->level), $registryLevels, true)) {
                $warnings[] = sprintf(
                    'Rejstřík „%s“ se u soudu „%s“ obvykle nevede – zkontrolujte značku.',
                    $spisovka->registry,
                    $court->name,
                );
            }
        }

        if ($registryLevels === [CourtLevel::SupremeAdministrative]) {
            $warnings[] = 'Jde o spisovou značku Nejvyššího správního soudu – infoSoud řízení NSS neobsahuje, sledujte www.nssoud.cz.';
        }

        return new SpisovkaResolution(
            spisovka: $spisovka,
            errors: $errors,
            warnings: $warnings,
            registrySuggestions: $suggestions,
            registryLevels: $registryLevels,
            registryDescription: $registryDescription,
            fixedCourtKod: $fixedCourtKod,
            fixedCourtReason: $fixedCourtReason,
            candidateCourtKods: $candidates,
        );
    }


    /**
     * Closest existing registry codes by edit distance.
     *
     * @return list<string>
     */
    private function suggestRegistry(string $inputNorm): array
    {
        $best = [];
        foreach ($this->registries->findAllNorms() as $norm) {
            $distance = levenshtein($inputNorm, $norm);
            if ($distance <= self::MaxSuggestionDistance && $distance < strlen($inputNorm)) {
                $best[$norm] = $distance;
            }
        }
        asort($best);
        // strval: PHP silently casts numeric-string array keys to int, PHPStan knows that
        return array_map(strval(...), array_slice(array_keys($best), 0, 3));
    }
}
