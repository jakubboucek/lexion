<?php declare(strict_types=1);

/**
 * Adaptive scan of case-number series from infosoud into the case file records
 * (docs/navrh-sken-rad.md). Fills each block's holes and finds its end with a
 * logarithmic number of probes; the confirmed end lands in `case_series_scan`,
 * every probe in case_file / case_lookup_miss. Run inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/infosoud-scan-series.php OSZPCPM T 5 2025
 *   docker compose exec -w /var/www/html web php bin/infosoud-scan-series.php --estimate=180 OSZPCPM T 5 2025
 *   docker compose exec -w /var/www/html web php bin/infosoud-scan-series.php --from=12001 --to=12999 OSSEMOS NC 12 2026
 *   docker compose exec -w /var/www/html web php bin/infosoud-scan-series.php --list=.data/scan.txt --delay=1
 *   docker compose exec -w /var/www/html web php bin/infosoud-scan-series.php --dry-run --list=.data/scan.txt
 *
 * A list-file line is "<court> <registry> <senate> <year>" plus optional
 * "from=", "to=", "estimate=" tokens in any order (# starts a comment).
 *
 * Options (must precede the positional arguments - getopt() stops parsing at
 * the first non-option):
 *   --list=<file>        read series from a file instead of argv
 *   --delay=<sec>        delay between actual infosoud requests (default: 1)
 *   --from=<n>           block start (argv mode; default 1)
 *   --to=<n>             hard ceiling never crossed (argv mode)
 *   --estimate=<n>       first-probe hint (argv mode)
 *   --confirm=<k>        consecutive misses that confirm the end (default: 3)
 *   --max-requests=<n>   whole-run cap on real upstream requests
 *   --dry-run            inventory + estimates only, no requests
 */

use App\Bootstrap;
use App\Model\CaseFile\CaseSeriesScanService;
use App\Model\CaseFile\SeriesScanTarget;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['list:', 'delay:', 'from:', 'to:', 'estimate:', 'confirm:', 'max-requests:', 'dry-run']);
$delay = max(0, (int) ($opts['delay'] ?? 1));
$confirm = max(1, (int) ($opts['confirm'] ?? 3));
$maxRequests = isset($opts['max-requests']) ? max(1, (int) $opts['max-requests']) : null;
$dryRun = isset($opts['dry-run']);

/**
 * Parses one series spec: 4 positional fields then key=value tokens. Returns a
 * target or throws with a message naming the offending source.
 *
 * @param list<string> $tokens
 */
$parseSpec = static function (array $tokens, string $where): SeriesScanTarget {
    $positional = [];
    $named = [];
    foreach ($tokens as $token) {
        if (str_contains($token, '=')) {
            [$key, $value] = explode('=', $token, 2);
            $named[$key] = $value;
        } else {
            $positional[] = $token;
        }
    }
    if (count($positional) !== 4) {
        throw new RuntimeException("$where: expected '<court> <registry> <senate> <year>', got: " . implode(' ', $tokens));
    }
    foreach ($named as $key => $_) {
        if (!in_array($key, ['from', 'to', 'estimate'], true)) {
            throw new RuntimeException("$where: unknown token '$key=' (allowed: from, to, estimate)");
        }
    }
    [$court, $registry, $senate, $year] = $positional;
    return new SeriesScanTarget(
        strtoupper($court),
        strtoupper($registry),
        (int) $senate,
        (int) $year,
        isset($named['from']) ? (int) $named['from'] : 1,
        isset($named['to']) ? (int) $named['to'] : null,
        isset($named['estimate']) ? (int) $named['estimate'] : null,
    );
};

/** @var list<SeriesScanTarget> $targets */
$targets = [];
try {
    if (isset($opts['list'])) {
        $listFile = is_string($opts['list']) ? $opts['list'] : null;
        $lines = $listFile !== null ? @file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($lines === false) {
            fwrite(STDERR, 'Cannot read list file: ' . ($listFile ?? '(invalid --list value)') . "\n");
            exit(1);
        }
        foreach ($lines as $n => $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            $targets[] = $parseSpec((array) preg_split('/\s+/', $line), 'line ' . ($n + 1));
        }
    } else {
        // getopt() leaves parsed options in $argv - drop them, keep positionals.
        $argvTokens = array_values(array_filter(
            array_slice($argv, 1),
            static fn(string $arg): bool => !str_starts_with($arg, '--'),
        ));
        $named = [];
        foreach (['from', 'to', 'estimate'] as $key) {
            if (isset($opts[$key])) {
                $named[] = "$key={$opts[$key]}";
            }
        }
        if ($argvTokens === []) {
            fwrite(STDERR, "Usage: php bin/infosoud-scan-series.php [options] <court> <registry> <senate> <year>\n");
            fwrite(STDERR, "       php bin/infosoud-scan-series.php --list=<file>\n");
            exit(1);
        }
        $targets[] = $parseSpec([...$argvTokens, ...$named], 'arguments');
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$container = (new Bootstrap)->bootConsoleApplication();
$scanner = $container->getByType(CaseSeriesScanService::class);

$progress = static function (string $line): void {
    echo $line . "\n";
    flush();
};

try {
    $results = $scanner->scan($targets, $delay, $dryRun, $maxRequests, $confirm, $progress);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Input error: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Scan aborted: ' . $e->getMessage() . "\n");
    exit(1);
}

$unconfirmed = 0;
foreach ($results as $result) {
    if ($result->confirmedEnd === null && $result->unconfirmedReason !== 'dry_run') {
        $unconfirmed++;
    }
}
printf("\nDone: %d series (%d without a confirmed end).\n", count($results), $unconfirmed);
exit($unconfirmed > 0 ? 1 : 0);
