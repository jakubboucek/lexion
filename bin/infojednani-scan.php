<?php declare(strict_types=1);

/**
 * Initial full scan of infoJednani (https://infojednani.gov.cz) hearings.
 *
 * Iterates DAY BY DAY (nearest day first), and within each day over every
 * court and every courtroom of that court, querying POST
 * /api/v1/jednani/vyhledej with the EXACT room label for each
 * (court, room, day) cell. The day-first order means the nearest dates are
 * complete early in the run: an interrupted long scan has already covered
 * the terms that matter most. This uses the API exactly as the public SPA does
 * (one request = one room + one day), so it does not rely on the unintended
 * "%" LIKE wildcard and keeps the room association (the room is only known from
 * the request parameter — the API never returns it per event). See
 * docs/infojednani-api.md.
 *
 * Every successful (HTTP 200) response is stored verbatim as a JSON file at:
 *   <output>/<court_kod>/<YYYY-MM-DD>/<room_file>.json
 * The file name derives from the room LABEL (ScanResponseFile::nameFor), not
 * from the room's position in the codelist - a positional name broke as soon
 * as the codelist drifted (an inserted room shifts every index behind it;
 * 2026-08-26 incident). Before writing, the response's echoed jednaciSin is
 * checked against the requested label; a mismatch is a hard failure - the
 * file is named after the label, so it must actually contain it.
 *
 * The scan is RESUMABLE: an already-existing target file is skipped, so the
 * job can be interrupted (Ctrl-C) and restarted; it picks up where it left off.
 * A failed request writes no file, so it is retried on the next run. Because
 * names are label-derived, dropping a REFRESHED _codelist.json into an
 * existing output directory is safe and useful: a resume then fetches exactly
 * the rooms (and days) not present yet.
 *
 * The scan window is fixed at launch (today .. today+days-1). During a long run
 * the wall-clock day advances, so a not-yet-fetched cell for what was "today" at
 * launch can roll into the past. The API rejects past dates (HTTP 400 / 0007),
 * so such a cell is refused LOCALLY (compared against the current day in
 * Europe/Prague) and skipped without sending the request — it would certainly
 * fail. These past-date skips are recorded in the scan log as status "skip_past"
 * so the coverage gap is traceable (that day can never be backfilled).
 *
 * The run is recorded in the application log (docs/logovani.md): one run per
 * invocation (resource hearing, action scan). Every actual HTTP attempt
 * (success or final failure) and every past-date refusal — NOT resume skips,
 * which are no-ops — goes to the run's 'attempts' JSONL channel, one object
 * per cell: {ts, status, court, date, room_idx, room, http, attempts,
 * events|error}. The durable record matters because a cell for a future day
 * that later becomes past can never be backfilled (the API rejects past
 * dates), so the log is the only trace of what was missed. The 'out' text
 * channel keeps the plan and the final summary; per-cell progress stays on
 * the console only. The DB is touched only at start and finish (the log
 * design); a run interrupted with Ctrl-C stays 'pending' — that is the
 * documented meaning of an unfinished run, and it never blocks a restart
 * (the scan resumes from the output files, not from the log).
 *
 * Run inside the dev container (per project rules — never run php on the host):
 *   docker compose exec -w /var/www/html web php bin/infojednani-scan.php
 *
 * Options (all optional):
 *   --out=<dir>       output directory (default: <repo>/.data/infojednani-scan)
 *   --days=<n>        number of days to scan, starting today (default: 30)
 *   --from=<Y-m-d>    first day to scan (default: today, Europe/Prague)
 *   --delay=<sec>     delay between hearing requests in seconds (default: 10)
 *   --skip-weekends   drop Saturdays and Sundays from the window (courts do
 *                     not hear on weekends; the rare weekend record observed
 *                     so far was a clerical error, cancelled upstream)
 */

use App\Bootstrap;
use App\Model\Hearing\HearingLogKind;
use App\Model\Hearing\ScanResponseFile;
use App\Model\Http\JsonHttpClient;
use App\Model\Log\LogRunChannel;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
use Nette\Database\Connection;

// The DI container is booted only for the application log (LogService); the
// scan itself needs no DB. The HTTP client is still constructed directly so
// the User-Agent and timeouts cannot drift from the web's InfosoudClient.
require __DIR__ . '/../web/vendor/autoload.php';

const API_BASE = 'https://infojednani.gov.cz/api/v1';
const CODELIST_DELAY = 1;   // seconds between codelist GETs at startup
const MAX_TRIES = 3;        // attempts per hearing request before giving up
const RETRY_BACKOFF = 5;    // seconds to wait before a retry
const TZ = 'Europe/Prague';

// ---- args ------------------------------------------------------------------

$opts = getopt('', ['out:', 'days:', 'from:', 'delay:', 'skip-weekends']);
$repoRoot = dirname(__DIR__);
// getopt() hands back an array for a repeated option and false for a flag -
// only a plain string is a usable value.
$outOpt = $opts['out'] ?? null;
$outDir = rtrim(is_string($outOpt) ? $outOpt : $repoRoot . '/.data/infojednani-scan', '/');
$days = max(1, (int) ($opts['days'] ?? 30));
/** @var int|float $delay */
$delay = max(0, (float) ($opts['delay'] ?? 1));
$skipWeekends = array_key_exists('skip-weekends', $opts);
$tz = new DateTimeZone(TZ);
$fromOpt = $opts['from'] ?? null;
try {
    $start = new DateTimeImmutable(is_string($fromOpt) ? $fromOpt : 'today', $tz);
} catch (Exception $e) {
    fwrite(STDERR, "Invalid --from date: {$e->getMessage()}\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0o777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create directory: $outDir\n");
    exit(1);
}

$container = (new Bootstrap)->bootConsoleApplication();
$logService = $container->getByType(LogService::class);
$dbConnection = $container->getByType(Connection::class);

// ---- http helpers ----------------------------------------------------------

/**
 * Delegates to the shared JsonHttpClient (single attempt - the scan loop does
 * its own retries so every attempt lands in the scan log).
 *
 * @return array{status:int, body:?string, error:string}
 */
function httpRequest(string $method, string $url, ?array $jsonBody = null): array
{
    static $client = new JsonHttpClient;
    return $client->attempt($url, $method === 'POST' ? ($jsonBody ?? []) : null);
}

function getJson(string $url): array
{
    $resp = httpRequest('GET', $url);
    if ($resp['status'] !== 200 || $resp['body'] === null) {
        throw new RuntimeException("GET $url failed: HTTP {$resp['status']} {$resp['error']}");
    }
    $data = json_decode($resp['body'], true, flags: JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : throw new RuntimeException("GET $url: unexpected payload");
}

function log_line(string $msg): void
{
    echo $msg . "\n";
}

/**
 * Atomic write via tmp + rename. An interrupted run (Ctrl-C, full disk) must
 * not leave a truncated file: the resume check trusts is_file(), and a cell
 * whose date meanwhile slips into the past could never be re-fetched.
 */
function writeAtomic(string $file, string $content): void
{
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $content) !== strlen($content)) {
        @unlink($tmp);
        throw new RuntimeException("Write failed: $tmp");
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("Rename failed: $tmp -> $file");
    }
}

// ---- codelist (courts + rooms), cached for resume --------------------------

$codelistFile = $outDir . '/_codelist.json';
if (is_file($codelistFile)) {
    log_line("Codelist: reusing cached $codelistFile");
    $codelist = json_decode((string) file_get_contents($codelistFile), true, flags: JSON_THROW_ON_ERROR);
    $courts = $codelist['soudy'];
} else {
    log_line('Codelist: fetching courts and rooms from infoJednani …');
    $top = getJson(API_BASE . '/organizace/lov');               // krajske + vrchni
    sleep(CODELIST_DELAY);
    $sub = getJson(API_BASE . '/organizace/podrizene/lov');     // okresni + obvodni
    $rawCourts = [...$top, ...$sub];

    $courts = [];
    foreach ($rawCourts as $i => $court) {
        $kod = $court['kod'];
        $rooms = getJson(API_BASE . '/organizace/lovkod/jednaci-sin?idOrganizace=' . rawurlencode($kod));
        $sine = array_map(static fn(array $r): string => $r['kod'], $rooms);
        $courts[] = ['kod' => $kod, 'nazev' => $court['nazev'], 'sine' => $sine];
        log_line(sprintf('  [%2d/%d] %s %s — %d síní', $i + 1, count($rawCourts), $kod, $court['nazev'], count($sine)));
        sleep(CODELIST_DELAY);
    }
    writeAtomic($codelistFile, json_encode([
        'stazeno' => (new DateTimeImmutable('now', $tz))->format(DateTimeInterface::ATOM),
        'zdroj' => API_BASE . '/organizace/lovkod/jednaci-sin?idOrganizace=<kod>',
        'soudy' => $courts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    log_line("Codelist: saved $codelistFile");
}

// ---- build work plan -------------------------------------------------------

$dates = [];
for ($d = 0; $d < $days; $d++) {
    $day = $start->add(new DateInterval("P{$d}D"));
    if ($skipWeekends && (int) $day->format('N') >= 6) {
        continue;
    }
    $dates[] = $day->format('Y-m-d');
}
if ($dates === []) {
    fwrite(STDERR, "Nothing to scan: the whole window falls on a weekend.\n");
    exit(1);
}
$roomsTotal = array_sum(array_map(static fn(array $c): int => count($c['sine']), $courts));
$total = $roomsTotal * count($dates);
$width = strlen((string) $total);

$etaHours = ($total * $delay) / 3600;
log_line('');
log_line(sprintf(
    'Plán: %d soudů, %d síní, %d dní (%s … %s%s) = %s requestů, den po dni od nejbližšího.',
    count($courts), $roomsTotal, count($dates), $dates[0], $dates[array_key_last($dates)],
    $skipWeekends ? ', bez víkendů' : '', number_format($total, 0, '', ' '),
));
log_line(sprintf('Odstup %.1f s → čistý čas skenu ~%.1f h. Výstup: %s', $delay, $etaHours, $outDir));
log_line('Skript je resumovatelný — Ctrl-C a znovu spuštění pokračuje tam, kde skončil.');
log_line('');

$session = $logService->buildRunSession(HearingLogKind::Scan, target: basename($outDir), data: [
    'out' => $outDir,
    'from' => $dates[0],
    'to' => $dates[array_key_last($dates)],
    'days' => count($dates),
    'skipWeekends' => $skipWeekends,
    'delay' => $delay,
    'total' => $total,
]);
$out = $session->textFile(LogRunChannel::Out);
$attempts = $session->jsonlFile('attempts');
$run = $session->start();
$out->writeLine(sprintf(
    'plan: %d courts, %d rooms, %d days (%s .. %s%s) = %d cells, day-first',
    count($courts), $roomsTotal, count($dates), $dates[0], $dates[array_key_last($dates)],
    $skipWeekends ? ', weekends skipped' : '', $total,
));

// ---- scan ------------------------------------------------------------------

$n = 0;
$counts = ['ok' => 0, 'skip' => 0, 'past' => 0, 'fail' => 0, 'events' => 0];

// Day-first: the nearest dates are fully covered early in the run, so an
// interrupted scan has already captured the terms that matter most.
foreach ($dates as $date) {
    foreach ($courts as $court) {
        $kod = $court['kod'];
        foreach ($court['sine'] as $idx => $sin) {
            $n++;
            $counter = sprintf('[%0' . $width . 'd/%d]', $n, $total);

            $dir = "$outDir/$kod/$date";
            $file = $dir . '/' . ScanResponseFile::nameFor($sin);
            if (is_file($file)) {
                $counts['skip']++;
                log_line("$counter SKIP $kod $date #" . sprintf('%03d', $idx) . '  (už staženo)');
                continue;
            }

            // The day may have advanced past this cell's date during a long run.
            // The API rejects past dates, so refuse locally (Europe/Prague) and
            // skip without wasting a request that would certainly fail.
            $todayPrague = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            if ($date < $todayPrague) {
                $counts['past']++;
                $attempts->write([
                    'status' => 'skip_past',
                    'court' => $kod,
                    'date' => $date,
                    'room_idx' => $idx,
                    'room' => $sin,
                    'today' => $todayPrague,
                ]);
                log_line("$counter PAST $kod $date #" . sprintf('%03d', $idx)
                    . "  (datum už v minulosti vůči $todayPrague — přeskočeno)");
                continue;
            }

            $payload = [
                'okresniSoud' => $kod,
                'jednaciSin' => $sin,
                'datumJednani' => $date,
                'typHledani' => 'JEDNANI',
            ];

            $resp = null;
            $tries = 0;
            for ($try = 1; $try <= MAX_TRIES; $try++) {
                $tries = $try;
                $resp = httpRequest('POST', API_BASE . '/jednani/vyhledej', $payload);
                if ($resp['status'] === 200 && $resp['body'] !== null) {
                    break;
                }
                if ($try < MAX_TRIES) {
                    log_line("$counter …  $kod $date #" . sprintf('%03d', $idx)
                        . "  HTTP {$resp['status']} — pokus $try/" . MAX_TRIES . ', čekám ' . RETRY_BACKOFF . 's');
                    sleep(RETRY_BACKOFF);
                }
            }

            // The file is named after the requested room label, so the
            // response must actually be about that room: verify the echoed
            // jednaciSin before writing. A mismatch (or undecodable body) is
            // a hard failure - never observed in practice, but storing it
            // under the label's name would silently misattribute the data.
            $decoded = $resp['status'] === 200 && $resp['body'] !== null
                ? json_decode($resp['body'], true)
                : null;
            $echoedRoom = is_array($decoded) ? ($decoded['jednaciSin'] ?? null) : null;

            if ($echoedRoom === $sin) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0o777, true);
                }
                writeAtomic($file, (string) $resp['body']);
                $events = is_array($decoded['udalosti'] ?? null) ? count($decoded['udalosti']) : 0;
                $counts['ok']++;
                $counts['events'] += $events;
                $attempts->write([
                    'status' => 'ok',
                    'court' => $kod,
                    'date' => $date,
                    'room_idx' => $idx,
                    'room' => $sin,
                    'http' => $resp['status'],
                    'attempts' => $tries,
                    'events' => $events,
                ]);
                log_line("$counter OK   $kod $date #" . sprintf('%03d', $idx)
                    . "  jednání=$events  \"$sin\"");
            } elseif ($resp['status'] === 200 && $resp['body'] !== null) {
                $counts['fail']++;
                $error = 'room label mismatch in response: ' . json_encode($echoedRoom, JSON_UNESCAPED_UNICODE);
                $attempts->write([
                    'status' => 'fail',
                    'court' => $kod,
                    'date' => $date,
                    'room_idx' => $idx,
                    'room' => $sin,
                    'http' => $resp['status'],
                    'attempts' => $tries,
                    'error' => $error,
                ]);
                log_line("$counter FAIL $kod $date #" . sprintf('%03d', $idx) . "  $error — soubor nezapsán");
            } else {
                $counts['fail']++;
                $attempts->write([
                    'status' => 'fail',
                    'court' => $kod,
                    'date' => $date,
                    'room_idx' => $idx,
                    'room' => $sin,
                    'http' => $resp['status'],
                    'attempts' => $tries,
                    'error' => $resp['error'],
                ]);
                log_line("$counter FAIL $kod $date #" . sprintf('%03d', $idx)
                    . "  HTTP {$resp['status']} {$resp['error']} — vzdávám po " . MAX_TRIES . ' pokusech');
            }

            if ($delay > 0) {
                usleep((int)($delay * 1_000_000));
            }
        }
    }
}

$out->writeLine(sprintf(
    'done: ok=%d resume_skip=%d past=%d fail=%d events=%d',
    $counts['ok'], $counts['skip'], $counts['past'], $counts['fail'], $counts['events'],
));
// A multi-day scan outlives the DB server's idle timeout and the connection
// has been unused since start(); reconnect so the finishing UPDATE cannot die
// on a gone-away connection. (An exception killing the scan mid-loop leaves
// the run 'pending' on purpose - see docs/logovani.md, crash detection.)
$dbConnection->reconnect();
if ($counts['fail'] > 0) {
    $run->finish(LogStatus::Failed, result: 'partial', message: 'some cells failed, rerun to backfill', resultData: $counts);
} else {
    $run->finish(LogStatus::Ok, resultData: $counts);
}

log_line('');
log_line(sprintf(
    'Hotovo: %d OK, %d přeskočeno, %d v minulosti, %d chyb; celkem nalezeno %d jednání.',
    $counts['ok'], $counts['skip'], $counts['past'], $counts['fail'], $counts['events'],
));
if ($counts['fail'] > 0) {
    log_line('Některé requesty selhaly — spusť skript znovu, doplní jen chybějící soubory.');
}
