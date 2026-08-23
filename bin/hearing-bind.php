<?php declare(strict_types=1);

/**
 * Binds hearings to case files on record and, where corroborated, promotes
 * the binding to 'confirmed'. Thin wrapper: the logic - the guess/confirm
 * phases, the cross-court matching rules, the logged run - lives in
 * HearingBindService so it is deployed with the application. Run inside the
 * dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/hearing-bind.php
 *   docker compose exec -w /var/www/html web php bin/hearing-bind.php --dry-run
 *
 * Options:
 *   --dry-run   report what would change, write nothing
 */

use App\Bootstrap;
use App\Model\Hearing\HearingBindService;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $opts);

$container = (new Bootstrap)->bootConsoleApplication();
$binder = $container->getByType(HearingBindService::class);

echo $dryRun ? "DRY RUN — nothing is written\n\n" : '';
$result = $binder->bind($dryRun, static function (string $line): void {
    echo "  $line\n";
});

echo "\nDone.\n";
printf("  linked by identity (venue_guess) %d\n", $result->linkedByIdentity);
printf("  confirmed via infoSoud           %d\n", $result->confirmed);
if ($result->relinked > 0) {
    printf("  re-linked to another case        %d\n", $result->relinked);
}
if ($result->crossCourt > 0) {
    printf("  home court != venue court        %d\n", $result->crossCourt);
}
if ($result->roomMismatch > 0) {
    printf("  rejected on room mismatch        %d\n", $result->roomMismatch);
}
