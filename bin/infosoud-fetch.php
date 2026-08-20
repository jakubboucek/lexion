<?php declare(strict_types=1);

/**
 * Fetches one case from the infosoud API into the case file records. Runs the
 * same CaseFileSyncService as the web detail, so it also fetches the first
 * own event detail and rebuilds the event/relation projections. Run inside
 * the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch.php <court_kod> "<spisovka>"
 *
 * Example: php bin/infosoud-fetch.php OSVYCTU "6 C 1/2023"
 */

use App\Bootstrap;
use App\Model\CaseFile\CaseFileRepository;
use App\Model\CaseFile\CaseFileSyncService;
use App\Model\Codelist\CourtRepository;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use Nette\Utils\Json;

require __DIR__ . '/../web/vendor/autoload.php';

[, $courtKod, $spisovkaText] = $argv + [null, null, null];

if ($courtKod === null || $spisovkaText === null) {
    fwrite(STDERR, "Usage: php bin/infosoud-fetch.php <court_kod> \"<spisovka>\"\n");
    exit(1);
}

$container = (new Bootstrap)->bootConsoleApplication();
$courts = $container->getByType(CourtRepository::class);
$parser = $container->getByType(SpisovkaParser::class);
$sync = $container->getByType(CaseFileSyncService::class);
$caseFiles = $container->getByType(CaseFileRepository::class);

$court = $courts->getByKod(strtoupper($courtKod));
if ($court === null) {
    fwrite(STDERR, "Unknown court: $courtKod\n");
    exit(1);
}
try {
    $spisovka = $parser->parse($spisovkaText);
} catch (SpisovkaParseException $e) {
    fwrite(STDERR, "Cannot parse spisovka: {$e->getMessage()}\n");
    exit(1);
}

$existing = $caseFiles->getByCase((string) $court->kod, $spisovka);

$stored = $sync->refreshFromInfosoud($court, $spisovka);
if ($stored === null) {
    echo "NOT FOUND: {$spisovka->format()} @ {$court->name}\n";
    exit(0);
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
