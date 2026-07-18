<?php declare(strict_types=1);

/**
 * Fetches one case from the infosoud API and stores the raw response into the
 * proceeding cache table. Run inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch.php <court_kod> "<spisovka>"
 *
 * Example: php bin/infosoud-fetch.php OSVYCTU "6 C 1/2023"
 */

use App\Bootstrap;
use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Proceeding\ProceedingRepository;
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
$client = $container->getByType(InfosoudClient::class);
$proceedings = $container->getByType(ProceedingRepository::class);

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

$result = $client->fetchCase($court, $spisovka->senate, $spisovka->registryNorm(), $spisovka->number, $spisovka->year);
if ($result === null) {
    echo "NOT FOUND: {$spisovka->format()} @ {$court->name}\n";
    exit(0);
}

$now = new DateTimeImmutable;
$existing = $proceedings->getByCase((string) $court->kod, $spisovka->registryNorm(), $spisovka->senate, $spisovka->number, $spisovka->year);
if ($existing === null) {
    $proceedings->insert([
        'court_kod' => (string) $court->kod,
        'registry_norm' => $spisovka->registryNorm(),
        'senate' => $spisovka->senate,
        'bc_number' => $spisovka->number,
        'year' => $spisovka->year,
        'infosoud_json' => Json::encode($result),
        'infosoud_at' => $now,
    ]);
    echo "INSERTED: ";
} else {
    $proceedings->update((int) $existing->id, [
        'infosoud_json' => Json::encode($result),
        'infosoud_at' => $now,
    ]);
    echo "UPDATED: ";
}
printf("%s @ %s | stav: %s | udalosti: %d\n",
    $spisovka->format(),
    $court->name,
    $result['stav'] ?? '-',
    count($result['udalosti'] ?? []),
);
