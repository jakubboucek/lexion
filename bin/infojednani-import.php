<?php declare(strict_types=1);

/**
 * Imports a finished infoJednani scan (bin/infojednani-scan.php output) into the
 * hearing tables. Run inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/infojednani-import.php
 *   docker compose exec -w /var/www/html web php bin/infojednani-import.php --dry-run
 *
 * Two phases:
 *  1) the codelist snapshot (_codelist.json) is upserted into `hearing_room`,
 *     each room classified into kind/off_site from its label (see classifyRoom);
 *  2) every stored response is walked and its events upserted into `hearing`,
 *     with the raw event kept in `hearing_observation`.
 *
 * Re-runs are idempotent: hearings are keyed by
 * (venue court, case identity, date, time) and observations by
 * (hearing, source, observed_at, room), so importing the same scan twice adds
 * nothing and writes nothing (an observation must be strictly newer than what
 * the row was last seen with to update it). When a newer observation arrives,
 * the mutable attributes (result, cancellation, type, judge) are refreshed and
 * last_seen_at is bumped.
 *
 * NOT done here (see docs/infojednani-api.md): linking `proceeding_id` and
 * upgrading `court_binding` to 'confirmed'. infoJednani alone only tells us the
 * court of the ROOM, which is a candidate home court, so every imported hearing
 * stays 'venue_guess' until cross-checked against infoSoud.
 *
 * Options:
 *   --dir=<dir>   scan directory (default: <repo>/.data/infojednani-scan)
 *   --dry-run     parse and report, write nothing
 */

use App\Bootstrap;
use App\Model\Hearing\HearingKey;
use App\Model\Hearing\HearingRepository;
use App\Model\Hearing\HearingRoom;
use App\Model\Hearing\HearingRoomRepository;
use App\Model\Hearing\RoomClassifier;
use App\Model\Spisovka\CaseYear;
use Nette\Database\Explorer;
use Nette\Utils\Json;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['dir:', 'dry-run']);
// getopt() hands back an array for a repeated option and false for a flag -
// only a plain string is a usable value.
$dirOpt = $opts['dir'] ?? null;
$scanDir = rtrim(is_string($dirOpt) ? $dirOpt : dirname(__DIR__) . '/.data/infojednani-scan', '/');
$dryRun = array_key_exists('dry-run', $opts);

if (!is_dir($scanDir)) {
    fwrite(STDERR, "Scan directory not found: $scanDir\n");
    exit(1);
}
$codelistFile = $scanDir . '/_codelist.json';
if (!is_file($codelistFile)) {
    fwrite(STDERR, "Codelist snapshot not found: $codelistFile\n");
    exit(1);
}

$container = (new Bootstrap)->bootConsoleApplication();
$db = $container->getByType(Explorer::class);
$hearings = $container->getByType(HearingRepository::class);
$rooms = $container->getByType(HearingRoomRepository::class);

$now = new DateTimeImmutable;
echo ($dryRun ? "DRY RUN — nothing is written\n" : "") . "Scan dir: $scanDir\n\n";

// ---- phase 1: room codelist -------------------------------------------------

$codelist = Json::decode((string) file_get_contents($codelistFile), forceArrays: true);
$knownCourts = $db->fetchPairs('SELECT kod, 1 FROM court');

$roomIds = [];    // HearingRoom::key() => id (real ids only)
$roomKnown = [];  // HearingRoom::key() => true (also filled in dry runs, so lookups still validate)
foreach ($rooms->findAll() as $room) {
    $roomIds[$room->key()] = $room->id;
    $roomKnown[$room->key()] = true;
}

$roomStats = ['inserted' => 0, 'updated' => 0, 'skipped_court' => 0];
$kindCounts = [];
foreach ($codelist['soudy'] as $court) {
    $kod = (string) $court['kod'];
    if (!isset($knownCourts[$kod])) {
        $roomStats['skipped_court']++;
        echo "  ! unknown court in codelist, skipping: $kod\n";
        continue;
    }
    foreach ($court['sine'] as $label) {
        $label = (string) $label;
        [$kind, $offSite] = RoomClassifier::classify($label);
        $kindCounts[$kind->value] = ($kindCounts[$kind->value] ?? 0) + 1;

        // Built even for a room we already know: it is what produces the
        // lookup key, so stored and fresh rooms can never key differently.
        $room = new HearingRoom;
        $room->courtKod = $kod;
        $room->label = $label;
        $room->kind = $kind;
        $room->offSite = $offSite;
        $room->firstSeen = $now;
        $room->lastSeen = $now;
        $key = $room->key();

        if (isset($roomKnown[$key])) {
            $roomStats['updated']++;
            if (!$dryRun) {
                $rooms->touchSeen($roomIds[$key], $now);
            }
            continue;
        }
        $roomStats['inserted']++;
        $roomKnown[$key] = true;
        if (!$dryRun) {
            $roomIds[$key] = $rooms->insert($room)->id;
        }
    }
}
printf(
    "Rooms: %d new, %d refreshed%s\n",
    $roomStats['inserted'],
    $roomStats['updated'],
    $roomStats['skipped_court'] > 0 ? ", {$roomStats['skipped_court']} courts skipped" : '',
);
ksort($kindCounts);
foreach ($kindCounts as $kind => $count) {
    printf("  %-10s %4d\n", $kind, $count);
}
echo "\n";

// ---- phase 2: hearings ------------------------------------------------------

// Existing hearings keyed the same way as the unique index, so a re-import
// resolves rows without a query per event.
$hearingIds = [];
$hearingSeen = [];
foreach ($db->fetchAll('SELECT id, venue_court_kod, registry_norm, senate, bc_number, year, hearing_date, hearing_time, last_seen_at FROM hearing') as $row) {
    $key = HearingKey::venueCaseTime(
        (string) $row->venue_court_kod,
        (string) $row->registry_norm,
        (int) $row->senate,
        (int) $row->bc_number,
        (int) $row->year,
        $row->hearing_date->format('Y-m-d'),
        HearingKey::timeFromDb($row->hearing_time),
    );
    $hearingIds[$key] = (int) $row->id;
    $hearingSeen[$key] = $row->last_seen_at instanceof DateTimeInterface
        ? $row->last_seen_at->format('Y-m-d H:i:s')
        : (string) $row->last_seen_at;
}

$files = glob($scanDir . '/*/*/*.json') ?: [];
sort($files);
$total = count($files);
printf("Response files: %s\n", number_format($total, 0, '', ' '));

$stats = ['files' => 0, 'events' => 0, 'new' => 0, 'refreshed' => 0, 'obs' => 0, 'unknown_room' => 0, 'bad' => 0];
$batch = 0;
if (!$dryRun) {
    $db->beginTransaction();
}

foreach ($files as $i => $file) {
    $stats['files']++;
    $parts = explode('/', $file);
    $courtKod = $parts[count($parts) - 3];
    $date = $parts[count($parts) - 2];
    if (!isset($knownCourts[$courtKod])) {
        $stats['bad']++;
        continue;
    }
    try {
        $payload = Json::decode((string) file_get_contents($file), forceArrays: true);
    } catch (Nette\Utils\JsonException) {
        $stats['bad']++;
        continue;
    }
    $roomLabel = isset($payload['jednaciSin']) ? (string) $payload['jednaciSin'] : null;
    $roomKey = $roomLabel !== null ? HearingRoom::keyOf($courtKod, $roomLabel) : null;
    $roomId = $roomKey !== null ? ($roomIds[$roomKey] ?? null) : null;
    if ($roomKey !== null && !isset($roomKnown[$roomKey])) {
        $stats['unknown_room']++;
    }
    // platneK is the upstream "valid as of" stamp = when this answer was true.
    $observedAt = isset($payload['platneK'])
        ? (new DateTimeImmutable((string) $payload['platneK']))->format('Y-m-d H:i:s')
        : $now->format('Y-m-d H:i:s');

    foreach ($payload['udalosti'] ?? [] as $event) {
        $stats['events']++;
        $time = substr((string) $event['cas'], 0, 5);
        // The scan holds the upstream token (61 = 1961); internally the year
        // is always full, matching proceeding.year so the two can be joined.
        $year = CaseYear::fromUpstream((int) $event['rocnik']);
        $key = HearingKey::venueCaseTime(
            $courtKod, (string) $event['druh'], (int) $event['cislo'], (int) $event['bcVec'],
            $year, $date, $time,
        );

        $attributes = [
            'room' => $roomLabel,
            'room_id' => $roomId,
            'hearing_type' => $event['druhJednani'] ?? null,
            'judge' => $event['resitel'] ?? null,
            'cancelled' => ($event['jednaniZruseno'] ?? null) === 'Ano' ? 1 : 0,
            'non_public' => ($event['neverejneJednani'] ?? null) === 'Ano' ? 1 : 0,
            'result' => $event['vysledek'] ?? null,
        ];

        if (!isset($hearingIds[$key])) {
            $stats['new']++;
            if (!$dryRun) {
                $row = $hearings->insert($attributes + [
                    'venue_court_kod' => $courtKod,
                    'registry_norm' => (string) $event['druh'],
                    'senate' => (int) $event['cislo'],
                    'bc_number' => (int) $event['bcVec'],
                    'year' => $year,
                    'hearing_date' => $date,
                    'hearing_time' => $time,
                    'last_seen_at' => $observedAt,
                ]);
                $hearingIds[$key] = (int) $row->id;
            } else {
                $hearingIds[$key] = -1; // placeholder so duplicates are detected in dry runs too
            }
            $hearingSeen[$key] = $observedAt;
        } elseif (($hearingSeen[$key] ?? '') < $observedAt) {
            // Newer (or equally fresh) observation wins for mutable attributes.
            // The room is deliberately NOT overwritten once set: a hearing
            // occasionally appears in two rooms and the first one stays primary,
            // both are preserved as observations.
            $stats['refreshed']++;
            if (!$dryRun) {
                unset($attributes['room'], $attributes['room_id']);
                $hearings->update($hearingIds[$key], $attributes + ['last_seen_at' => $observedAt]);
            }
            $hearingSeen[$key] = $observedAt;
        }

        if ($dryRun) {
            $stats['obs']++;
        } else {
            $written = $hearings->insertObservationIgnore([
                'hearing_id' => $hearingIds[$key],
                'source' => 'infojednani',
                'observed_at' => $observedAt,
                'room' => $roomLabel,
                'raw_json' => Json::encode($event),
            ]);
            $stats['obs'] += $written ? 1 : 0;
        }
    }

    if (!$dryRun && ++$batch >= 500) {
        $db->commit();
        $db->beginTransaction();
        $batch = 0;
    }
    if (($i + 1) % 5000 === 0 || $i + 1 === $total) {
        printf(
            "  [%s/%s] events=%s new=%s refreshed=%s\n",
            number_format($i + 1, 0, '', ' '), number_format($total, 0, '', ' '),
            number_format($stats['events'], 0, '', ' '),
            number_format($stats['new'], 0, '', ' '),
            number_format($stats['refreshed'], 0, '', ' '),
        );
    }
}
if (!$dryRun) {
    $db->commit();
}

echo "\nDone.\n";
printf("  files            %s\n", number_format($stats['files'], 0, '', ' '));
printf("  events           %s\n", number_format($stats['events'], 0, '', ' '));
printf("  hearings new     %s\n", number_format($stats['new'], 0, '', ' '));
printf("  hearings updated %s\n", number_format($stats['refreshed'], 0, '', ' '));
printf("  observations     %s\n", number_format($stats['obs'], 0, '', ' '));
if ($stats['unknown_room'] > 0) {
    printf("  ! responses whose room is not in the codelist: %d\n", $stats['unknown_room']);
}
if ($stats['bad'] > 0) {
    printf("  ! unreadable/unknown-court files: %d\n", $stats['bad']);
}
