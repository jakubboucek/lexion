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
 * NOT done here (see docs/infojednani-api.md): linking `case_file_id` and
 * upgrading `court_binding` to 'confirmed'. infoJednani alone only tells us the
 * court of the ROOM, which is a candidate home court, so every imported hearing
 * stays 'venue_guess' until cross-checked against infoSoud.
 *
 * Options:
 *   --dir=<dir>   scan directory (default: <repo>/.data/infojednani-scan)
 *   --dry-run     parse and report, write nothing
 */

use App\Bootstrap;
use App\Model\Hearing\Hearing;
use App\Model\Hearing\HearingLogKind;
use App\Model\Hearing\HearingObservation;
use App\Model\Hearing\HearingRepository;
use App\Model\Hearing\HearingRoom;
use App\Model\Hearing\HearingRoomRepository;
use App\Model\Hearing\ObservationSource;
use App\Model\Hearing\RoomClassifier;
use App\Model\Log\LogRunChannel;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
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

// The whole import is one logged run (docs/logovani.md); a dry run is
// a run too. An uncaught crash leaves the row pending with the streams closed.
$log = $container->getByType(LogService::class);
$session = $log->buildRunSession(HearingLogKind::ScanImport, data: ['scanDir' => $scanDir, 'dryRun' => $dryRun]);
$out = $session->textFile(LogRunChannel::Out);
$run = $session->start();

$now = new DateTimeImmutable;
echo ($dryRun ? "DRY RUN — nothing is written\n" : "") . "Scan dir: $scanDir\n\n";
$out->writeLine(($dryRun ? '[dry-run] ' : '') . "scan dir: $scanDir");

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
        $out->writeLine("unknown court in codelist, skipping: $kod");
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
$out->writeLine(sprintf(
    'rooms: %d new, %d refreshed, %d courts skipped',
    $roomStats['inserted'], $roomStats['updated'], $roomStats['skipped_court'],
));
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
foreach ($hearings->streamAll() as $hearing) {
    $hearingIds[$hearing->key()] = $hearing->id;
    $hearingSeen[$hearing->key()] = $hearing->lastSeenAt;
}

$files = glob($scanDir . '/*/*/*.json') ?: [];
sort($files);
$total = count($files);
printf("Response files: %s\n", number_format($total, 0, '', ' '));
$out->writeLine("response files: $total");

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
        ? new DateTimeImmutable((string) $payload['platneK'])
        : $now;

    foreach ($payload['udalosti'] ?? [] as $event) {
        $stats['events']++;
        $time = substr((string) $event['cas'], 0, 5);
        // The scan holds the upstream token (61 = 1961); internally the year
        // is always full, matching case_file.year so the two can be joined.
        $year = CaseYear::fromUpstream((int) $event['rocnik']);
        // The attributes a newer observation is allowed to refresh; the room
        // is deliberately NOT among them (see the update branch below).
        $applyAttributes = static function (Hearing $hearing) use ($event, $observedAt): void {
            $hearing->hearingType = isset($event['druhJednani']) ? (string) $event['druhJednani'] : null;
            $hearing->judge = isset($event['resitel']) ? (string) $event['resitel'] : null;
            $hearing->cancelled = ($event['jednaniZruseno'] ?? null) === 'Ano';
            $hearing->nonPublic = ($event['neverejneJednani'] ?? null) === 'Ano';
            $hearing->result = isset($event['vysledek']) ? (string) $event['vysledek'] : null;
            $hearing->lastSeenAt = $observedAt;
        };

        $hearing = new Hearing;
        $hearing->venueCourtKod = $courtKod;
        $hearing->registryNorm = (string) $event['druh'];
        $hearing->senate = (int) $event['cislo'];
        $hearing->bcNumber = (int) $event['bcVec'];
        $hearing->year = $year;
        $hearing->hearingDate = new DateTimeImmutable($date);
        // A #[Type\Time] value carries only the wall clock; the hydrator pins
        // it to 0001-01-01 and stores just H:i:s.
        $hearing->hearingTime = new DateTimeImmutable("0001-01-01 $time");
        $hearing->room = $roomLabel;
        $hearing->roomId = $roomId;
        $applyAttributes($hearing);
        // The entity keys itself, so stored and freshly parsed hearings can
        // never key differently.
        $key = $hearing->key();

        if (!isset($hearingIds[$key])) {
            $stats['new']++;
            if (!$dryRun) {
                $hearingIds[$key] = $hearings->insert($hearing)->id;
            } else {
                $hearingIds[$key] = -1; // placeholder so duplicates are detected in dry runs too
            }
            $hearingSeen[$key] = $observedAt;
        } elseif (($hearingSeen[$key] ?? null) === null || $hearingSeen[$key] < $observedAt) {
            // Newer (or equally fresh) observation wins for mutable attributes.
            // The room is deliberately NOT overwritten once set: a hearing
            // occasionally appears in two rooms and the first one stays primary,
            // both are preserved as observations.
            $stats['refreshed']++;
            if (!$dryRun) {
                $changes = new Hearing;
                $applyAttributes($changes);
                $hearings->update($hearingIds[$key], $changes);
            }
            $hearingSeen[$key] = $observedAt;
        }

        if ($dryRun) {
            $stats['obs']++;
        } else {
            $observation = new HearingObservation;
            $observation->hearingId = $hearingIds[$key];
            $observation->source = ObservationSource::Infojednani;
            $observation->observedAt = $observedAt;
            $observation->room = $roomLabel;
            $observation->rawJson = Json::encode($event);
            $stats['obs'] += $hearings->insertObservationIgnore($observation) ? 1 : 0;
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
        $out->writeLine(sprintf(
            'processed %d/%d files (events=%d new=%d refreshed=%d)',
            $i + 1, $total, $stats['events'], $stats['new'], $stats['refreshed'],
        ));
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

$run->finish(LogStatus::Ok, resultData: [
    'dryRun' => $dryRun,
    'rooms' => $roomStats,
    'files' => $stats['files'],
    'events' => $stats['events'],
    'hearingsNew' => $stats['new'],
    'hearingsRefreshed' => $stats['refreshed'],
    'observations' => $stats['obs'],
    'unknownRoom' => $stats['unknown_room'],
    'badFiles' => $stats['bad'],
]);
