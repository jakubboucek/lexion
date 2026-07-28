<?php declare(strict_types=1);

/**
 * POC harness: hydrates every real `proceeding` row into the CaseFile entity
 * and extracts it back, verifying roundtrip fidelity and measuring speed.
 * The file is byte-identical across the poc-* branches - only the entity and
 * the CaseFileMapper implementation differ - so the branches diff cleanly
 * against each other. Run inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/poc-hydration.php
 */

use App\Bootstrap;
use App\Model\Poc\CaseFileMapper;
use Nette\Database\Explorer;

require __DIR__ . '/../web/vendor/autoload.php';

$container = (new Bootstrap)->bootConsoleApplication();
$db = $container->getByType(Explorer::class);

/**
 * Canonical scalar image of a value so rows and extracted rows can be diffed.
 * Date-like strings canonicalize like date objects: a variant may hand the
 * DB a formatted string instead of an instance and still be value-correct.
 */
function canonical(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        $value instanceof DateTimeInterface => 'dt:' . $value->format('Y-m-d H:i:s'),
        $value instanceof DateInterval => 'iv:' . $value->format('%H:%I:%S'),
        is_bool($value) => 'b:' . ($value ? '1' : '0'),
        is_string($value) && preg_match('~^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})~', $value, $m)
            => 'dt:' . $m[1] . ' ' . $m[2],
        is_scalar($value) => 's:' . $value,
        default => 'o:' . (is_object($value) ? $value::class : gettype($value)),
    };
}

$rows = array_map(iterator_to_array(...), $db->fetchAll('SELECT * FROM proceeding'));
printf("Loaded %d proceeding rows.\n", count($rows));

$mapper = new CaseFileMapper();

// ---- hydrate ----------------------------------------------------------------

$entities = [];
$errors = 0;
$firstErrors = [];
$start = microtime(true);
foreach ($rows as $i => $row) {
    try {
        $entities[$i] = $mapper->fromRow($row);
    } catch (Throwable $e) {
        $errors++;
        if (count($firstErrors) < 3) {
            $firstErrors[] = mb_substr($e->getMessage(), 0, 220);
        }
    }
}
$hydrateSeconds = microtime(true) - $start;

// ---- extract + roundtrip diff ----------------------------------------------

$mismatchedRows = 0;
$mismatchKinds = [];
$start = microtime(true);
foreach ($entities as $i => $entity) {
    $back = $mapper->toRow($entity);
    $mismatch = false;
    foreach ($rows[$i] as $column => $value) {
        $expected = canonical($value);
        $actual = array_key_exists($column, $back) ? canonical($back[$column]) : '(chybí sloupec)';
        if ($expected !== $actual) {
            $mismatch = true;
            $mismatchKinds[$column] ??= "$expected -> $actual";
        }
    }
    if ($mismatch) {
        $mismatchedRows++;
    }
}
$extractSeconds = microtime(true) - $start;

// ---- report -----------------------------------------------------------------

printf("hydrated %d | errors %d\n", count($entities), $errors);
foreach ($firstErrors as $error) {
    printf("  ! %s\n", $error);
}
printf("roundtrip mismatched rows: %d\n", $mismatchedRows);
foreach ($mismatchKinds as $column => $example) {
    printf("  ~ %-16s %s\n", $column, $example);
}
if ($entities !== []) {
    printf(
        "speed: hydrate %.2f s (%.0f rows/s), extract %.2f s (%.0f rows/s)\n",
        $hydrateSeconds,
        count($entities) / max($hydrateSeconds, 0.001),
        $extractSeconds,
        count($entities) / max($extractSeconds, 0.001),
    );
}
