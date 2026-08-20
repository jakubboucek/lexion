<?php declare(strict_types=1);

/**
 * Data migration: projects the stored raw infosoud JSON of every case file
 * on record into the derived tables created by
 * migrations/structures/2026-07-19-03-create-proceeding-event-table.sql and
 * 2026-07-19-04-create-relation-tables.sql (thin event rows + relations).
 *
 * Written against the pre-rename schema; the identifiers were carried over to
 * case_file* by 2026-08-20-00, so re-running this needs that migration applied
 * first. The file name keeps its original date and wording on purpose - it is
 * the ledger entry of what was applied.
 *
 * Uses CaseFileProjectionService - the same code the live sync runs - so
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
use App\Model\CaseFile\CaseFileProjectionService;
use App\Model\CaseFile\CaseFileRepository;
use App\Model\CaseFile\DataSource;
use Nette\Database\Explorer;

require __DIR__ . '/../../web/vendor/autoload.php';

$container = (new Bootstrap)->bootConsoleApplication();
$caseFiles = $container->getByType(CaseFileRepository::class);
$projection = $container->getByType(CaseFileProjectionService::class);
$db = $container->getByType(Explorer::class);

$dryRun = in_array('--dry-run', $argv, true);

// Guard: the target tables must exist.
foreach (['case_file_event', 'case_file_relation', 'relation_type'] as $table) {
    if (!$db->query("SHOW TABLES LIKE ?", $table)->fetchField()) {
        fwrite(STDERR, "Table `$table` is missing - apply the structure migrations "
            . "2026-07-19-03 and 2026-07-19-04 first.\n");
        exit(1);
    }
}

$count = 0;

foreach ($caseFiles->streamWithSource(DataSource::Infosoud) as $row) {
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
    $events = $db->table('case_file_event')->where('case_file_id', $row->id)->count('*');
    printf("  id=%-6d %-30s events=%d\n", $row->id, $label, $events);
    $count++;
}

echo "\n";
echo ($dryRun ? '[DRY-RUN] ' : '') . "Processed case files: $count\n";
if (!$dryRun) {
    printf(
        "Totals: %d events, %d relations\n",
        $db->table('case_file_event')->count('*'),
        $db->table('case_file_relation')->count('*'),
    );
}
