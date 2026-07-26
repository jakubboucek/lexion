<?php declare(strict_types=1);

/**
 * Imports an ISIR monthly published-cases listing (saved "vysledek_lustrace"
 * HTML page) into the proceeding cache table. Run inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/isir-import-listing.php <YYYY-MM> <file.html>
 *
 * The listing label (YYYY-MM) is recorded in isir_json.seenInListings. Re-runs
 * are idempotent: debtors and listing labels are merged, senate is refreshed.
 */

use App\Bootstrap;
use App\Model\Codelist\CourtPrefixRepository;
use App\Model\Proceeding\ProceedingRepository;
use Nette\Utils\Json;

require __DIR__ . '/../web/vendor/autoload.php';

[, $listingLabel, $file] = $argv + [null, null, null];

if ($listingLabel === null || $file === null || !preg_match('~^\d{4}-\d{2}$~', $listingLabel)) {
    fwrite(STDERR, "Usage: php bin/isir-import-listing.php <YYYY-MM> <file.html>\n");
    exit(1);
}
$html = @file_get_contents($file);
if ($html === false) {
    fwrite(STDERR, "Cannot read file: $file\n");
    exit(1);
}

$container = (new Bootstrap)->bootConsoleApplication();
$proceedings = $container->getByType(ProceedingRepository::class);
$prefixes = $container->getByType(CourtPrefixRepository::class);

// Parse listing rows: prefix | senate | registry | "number /" | year | court
// name | started at | debtor name | IC | RC (one row per debtor).
preg_match_all('~<tr>(.*?)</tr>~s', $html, $rowMatches);
$cases = [];
foreach ($rowMatches[1] as $row) {
    preg_match_all('~<td[^>]*>(.*?)</td>~s', $row, $cellMatches);
    $cells = array_map(
        static fn(string $cell) => trim((string) preg_replace('~\s+~u', ' ', strip_tags($cell))),
        $cellMatches[1],
    );
    if (count($cells) < 10 || !preg_match('~^[A-Z]{2,5}$~', $cells[0]) || !ctype_digit($cells[1])) {
        continue;
    }
    [$prefix, $senate, $registry] = [$cells[0], (int) $cells[1], strtoupper($cells[2])];
    $number = (int) trim($cells[3], ' /');
    // The listing prints the year in full. Anything below 1900 is a misparsed
    // row, not an old case - the year floor matches CaseYear (insolvency itself
    // only starts in 2008, so this never rejects a real row).
    $year = (int) $cells[4];
    if ($number === 0 || $year < 1900) {
        continue;
    }
    $key = "$prefix|$registry|$number|$year";
    $cases[$key] ??= [
        'prefix' => $prefix,
        'senate' => $senate,
        'registry' => $registry,
        'number' => $number,
        'year' => $year,
        'startedAt' => $cells[6],
        'debtors' => [],
    ];
    $debtor = array_filter(['name' => $cells[7], 'ic' => $cells[8], 'rc' => $cells[9]], static fn($v) => $v !== '');
    if ($debtor !== []) {
        $cases[$key]['debtors'][] = $debtor;
    }
}

if ($cases === []) {
    fwrite(STDERR, "No listing rows recognized in $file\n");
    exit(1);
}

$now = new DateTimeImmutable;
$inserted = $updated = 0;
$unknownPrefixes = [];

foreach ($cases as $case) {
    $prefixRow = $prefixes->getByPrefix($case['prefix']);
    if ($prefixRow === null) {
        $unknownPrefixes[$case['prefix']] = true;
        continue;
    }
    $courtKod = (string) $prefixRow->court_kod;

    $existing = $proceedings->getByCase($courtKod, $case['registry'], $case['senate'], $case['number'], $case['year']);
    $json = [
        'isirPrefix' => $case['prefix'],
        'startedAt' => $case['startedAt'],
        'debtors' => $case['debtors'],
        'seenInListings' => [$listingLabel],
    ];

    if ($existing === null) {
        $proceedings->insert([
            'court_kod' => $courtKod,
            'registry_norm' => $case['registry'],
            'senate' => $case['senate'],
            'bc_number' => $case['number'],
            'year' => $case['year'],
            'isir_json' => Json::encode($json),
            'isir_at' => $now,
        ]);
        $inserted++;
        continue;
    }

    // Merge into the previously stored JSON (debtors and listing labels union).
    $current = $existing->isir_json !== null ? Json::decode((string) $existing->isir_json, forceArrays: true) : [];
    $mergedDebtors = array_merge($current['debtors'] ?? [], $case['debtors']);
    $json['debtors'] = array_values(array_intersect_key($mergedDebtors, array_unique(array_map(Json::encode(...), $mergedDebtors))));
    $json['seenInListings'] = array_values(array_unique(array_merge($current['seenInListings'] ?? [], [$listingLabel])));
    $json['startedAt'] = $current['startedAt'] ?? $json['startedAt'];

    $proceedings->update((int) $existing->id, [
        'isir_json' => Json::encode($current === [] ? $json : array_merge($current, $json)),
        'isir_at' => $now,
    ]);
    $updated++;
}

printf(
    "Imported %s: %d cases (%d inserted, %d updated)%s\n",
    $listingLabel,
    $inserted + $updated,
    $inserted,
    $updated,
    $unknownPrefixes !== [] ? ' | UNKNOWN PREFIXES: ' . implode(', ', array_keys($unknownPrefixes)) : '',
);
