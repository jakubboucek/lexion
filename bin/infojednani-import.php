<?php declare(strict_types=1);

/**
 * Imports a finished infoJednani scan (bin/infojednani-scan.php output) into
 * the hearing tables. Thin wrapper: the logic - phases, merge rules,
 * idempotency, the logged run - lives in HearingScanImportService so it is
 * deployed with the application. Run inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/infojednani-import.php
 *   docker compose exec -w /var/www/html web php bin/infojednani-import.php --dry-run
 *
 * Options:
 *   --dir=<dir>   scan directory (default: <repo>/.data/infojednani-scan)
 *   --dry-run     parse and report, write nothing
 */

use App\Bootstrap;
use App\Model\Hearing\HearingScanImportService;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['dir:', 'dry-run']);
// getopt() hands back an array for a repeated option and false for a flag -
// only a plain string is a usable value.
$dirOpt = $opts['dir'] ?? null;
$scanDir = is_string($dirOpt) ? $dirOpt : dirname(__DIR__) . '/.data/infojednani-scan';
$dryRun = array_key_exists('dry-run', $opts);

$container = (new Bootstrap)->bootConsoleApplication();
$importer = $container->getByType(HearingScanImportService::class);

echo $dryRun ? "DRY RUN — nothing is written\n\n" : '';
try {
    $result = $importer->import($scanDir, $dryRun, static function (string $line): void {
        echo "  $line\n";
    });
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
printf("  rooms new        %s\n", number_format($result->roomsInserted, 0, '', ' '));
printf("  rooms refreshed  %s\n", number_format($result->roomsRefreshed, 0, '', ' '));
foreach ($result->roomKinds as $kind => $count) {
    printf("    %-10s %4d\n", $kind, $count);
}
printf("  files            %s\n", number_format($result->files, 0, '', ' '));
printf("  events           %s\n", number_format($result->events, 0, '', ' '));
printf("  hearings new     %s\n", number_format($result->hearingsNew, 0, '', ' '));
printf("  hearings updated %s\n", number_format($result->hearingsRefreshed, 0, '', ' '));
printf("  observations     %s\n", number_format($result->observations, 0, '', ' '));
if ($result->unknownRoom > 0) {
    printf("  ! responses whose room is not in the codelist: %d\n", $result->unknownRoom);
}
if ($result->badFiles > 0) {
    printf("  ! unreadable/unknown-court files: %d\n", $result->badFiles);
}
