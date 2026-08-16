<?php declare(strict_types=1);

/**
 * Data migration: projects the stored raw infosoud JSON of every cached
 * proceeding into the derived tables created by
 * migrations/structures/2026-07-19-03-create-proceeding-event-table.sql and
 * 2026-07-19-04-create-relation-tables.sql (thin event rows + relations).
 *
 * Uses ProceedingProjectionService - the same code the live sync runs - so
 * the script is idempotent and safe to re-run (events are upsert-paired,
 * relations rebuilt; manual relations are never touched).
 *
 * Usage (DEV):
 *   docker compose exec -w /var/www/html web php migrations/data/2026-07-19-00-project-proceeding-events-relations.php --dry-run
 *   docker compose exec -w /var/www/html web php migrations/data/2026-07-19-00-project-proceeding-events-relations.php
 *
 * Production: run the same script once after applying both structure
 * migrations. Take a database backup first.
 */

use App\Bootstrap;
use App\Model\Proceeding\DataSource;
use App\Model\Proceeding\ProceedingProjectionService;
use App\Model\Proceeding\ProceedingRepository;
use Nette\Database\Explorer;

require __DIR__ . '/../../web/vendor/autoload.php';

$container = (new Bootstrap)->bootConsoleApplication();
$proceedings = $container->getByType(ProceedingRepository::class);
$projection = $container->getByType(ProceedingProjectionService::class);
$db = $container->getByType(Explorer::class);

$dryRun = in_array('--dry-run', $argv, true);

// Guard: the target tables must exist.
foreach (['proceeding_event', 'proceeding_relation', 'relation_type'] as $table) {
    if (!$db->query("SHOW TABLES LIKE ?", $table)->fetchField()) {
        fwrite(STDERR, "Table `$table` is missing - apply the structure migrations "
            . "2026-07-19-03 and 2026-07-19-04 first.\n");
        exit(1);
    }
}

$count = 0;

foreach ($proceedings->streamWithSource(DataSource::Infosoud) as $row) {
    $label = sprintf(
        '%s %d %s %d/%d',
        $row->courtKod,
        $row->senate,
        $row->registryNorm,
        $row->bcNumber,
        $row->year,
    );
    if ($dryRun) {
        printf("  [dry] id=%-6d %s\n", $row->id, $label);
        $count++;
        continue;
    }
    $projection->projectInfosoud($row);
    $events = $db->table('proceeding_event')->where('proceeding_id', $row->id)->count('*');
    printf("  id=%-6d %-30s events=%d\n", $row->id, $label, $events);
    $count++;
}

echo "\n";
echo ($dryRun ? '[DRY-RUN] ' : '') . "Processed proceedings: $count\n";
if (!$dryRun) {
    printf(
        "Totals: %d events, %d relations\n",
        $db->table('proceeding_event')->count('*'),
        $db->table('proceeding_relation')->count('*'),
    );
}
