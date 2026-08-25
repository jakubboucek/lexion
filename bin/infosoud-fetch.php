<?php declare(strict_types=1);

/**
 * Fetches one or more cases from the infosoud API into the case file records.
 * Runs the same CaseFileSyncService as the web detail, so it also fetches the
 * first own event detail and rebuilds the event/relation projections. Run
 * inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch.php OSVYCTU "6 C 1/2023"
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch.php --list=cases.txt
 *
 * The list file holds one "<court_kod> <spisovka>" per line (# starts a comment).
 *
 * Options:
 *   --list=<file>   read cases from a file instead of argv
 *   --delay=<sec>   delay between infosoud requests (default: 3)
 */

use App\Bootstrap;
use App\Model\CaseFile\CaseFileRepository;
use App\Model\CaseFile\CaseFileSyncService;
use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use Nette\Utils\Json;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['list:', 'delay:']);
$delay = max(0, (int) ($opts['delay'] ?? 1));

/** @var list<array{0:string,1:string}> $cases */
$cases = [];
if (isset($opts['list'])) {
    // getopt() hands back an array for a repeated option and false for a
    // flag - only a plain string is a usable value.
    $listFile = is_string($opts['list']) ? $opts['list'] : null;
    $lines = $listFile !== null ? @file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if ($lines === false) {
        fwrite(STDERR, 'Cannot read list file: ' . ($listFile ?? '(invalid --list value)') . "\n");
        exit(1);
    }
    foreach ($lines as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '') {
            continue;
        }
        [$kod, $spisovka] = explode(' ', $line, 2) + [null, null];
        if ($kod === null || $spisovka === null) {
            fwrite(STDERR, "Skipping malformed line: $line\n");
            continue;
        }
        $cases[] = [$kod, trim($spisovka)];
    }
} else {
    $argvCases = array_slice($argv, 1);
    if (count($argvCases) < 2) {
        fwrite(STDERR, "Usage: php bin/infosoud-fetch.php <court_kod> \"<spisovka>\"\n");
        fwrite(STDERR, "       php bin/infosoud-fetch.php --list=<file>\n");
        exit(1);
    }
    $cases[] = [$argvCases[0], $argvCases[1]];
}

$container = (new Bootstrap)->bootConsoleApplication();
$courts = $container->getByType(CourtRepository::class);
$parser = $container->getByType(SpisovkaParser::class);
$sync = $container->getByType(CaseFileSyncService::class);
$caseFiles = $container->getByType(CaseFileRepository::class);

$total = count($cases);
$stats = ['updated' => 0, 'inserted' => 0, 'notFound' => 0, 'failed' => 0];

foreach ($cases as $i => [$kod, $spisovkaText]) {
    if ($total > 1) {
        printf("[%d/%d] ", $i + 1, $total);
    }

    $court = $courts->getByKod(strtoupper($kod));
    if ($court === null) {
        echo "! unknown court: $kod\n";
        $stats['failed']++;
        continue;
    }
    try {
        $spisovka = $parser->parse($spisovkaText);
    } catch (SpisovkaParseException $e) {
        echo "! cannot parse spisovka \"$spisovkaText\": {$e->getMessage()}\n";
        $stats['failed']++;
        continue;
    }

    $existing = $caseFiles->getByCase((string) $court->kod, $spisovka);

    try {
        $stored = $sync->refreshFromInfosoud($court, $spisovka);
    } catch (InfosoudApiException $e) {
        echo "! infosoud error: {$spisovka->format()} @ {$court->name}: {$e->getMessage()}\n";
        $stats['failed']++;
        sleep($delay);
        continue;
    }
    if ($stored === null) {
        echo "NOT FOUND: {$spisovka->format()} @ {$court->name}\n";
        $stats['notFound']++;
        sleep($delay);
        continue;
    }

    $case = Json::decode((string) $stored->infosoudJson, forceArrays: true);
    printf("%s: %s @ %s | stav: %s | udalosti: %d | firstEventDetail: %s\n",
        $existing === null ? 'INSERTED' : 'UPDATED',
        $spisovka->format(),
        $court->name,
        $case['stav'] ?? '-',
        count($case['udalosti'] ?? []),
        isset($case['firstEventDetail']) ? 'yes' : 'no',
    );
    $stats[$existing === null ? 'inserted' : 'updated']++;
    if ($i + 1 < $total) {
        sleep($delay);
    }
}

if ($total > 1) {
    printf(
        "\nDone: %d updated, %d inserted, %d not found, %d failed (of %d)\n",
        $stats['updated'],
        $stats['inserted'],
        $stats['notFound'],
        $stats['failed'],
        $total,
    );
    if ($stats['notFound'] > 0 || $stats['failed'] > 0) {
        exit(1);
    }
}
