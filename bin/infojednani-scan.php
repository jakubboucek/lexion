<?php declare(strict_types=1);

/**
 * Initial full scan of infoJednani (https://infojednani.gov.cz) hearings.
 *
 * Iterates over every court and every courtroom of that court, and for each
 * (court, room, day) in a rolling window queries POST /api/v1/jednani/vyhledej
 * with the EXACT room label. This uses the API exactly as the public SPA does
 * (one request = one room + one day), so it does not rely on the unintended
 * "%" LIKE wildcard and keeps the room association (the room is only known from
 * the request parameter — the API never returns it per event). See
 * docs/infojednani-api.md.
 *
 * Every successful (HTTP 200) response is stored verbatim as a JSON file at:
 *   <output>/<court_kod>/<YYYY-MM-DD>/<room_index>.json
 * The room index is the room's position in the per-court codelist snapshot
 * stored at <output>/_codelist.json.
 *
 * The scan is RESUMABLE: an already-existing target file is skipped, so the
 * job can be interrupted (Ctrl-C) and restarted; it picks up where it left off.
 * A failed request writes no file, so it is retried on the next run.
 *
 * The scan window is fixed at launch (today .. today+days-1). During a long run
 * the wall-clock day advances, so a not-yet-fetched cell for what was "today" at
 * launch can roll into the past. The API rejects past dates (HTTP 400 / 0007),
 * so such a cell is refused LOCALLY (compared against the current day in
 * Europe/Prague) and skipped without sending the request — it would certainly
 * fail. These past-date skips are recorded in the scan log as status "skip_past"
 * so the coverage gap is traceable (that day can never be backfilled).
 *
 * Every actual HTTP attempt (success or final failure — NOT skips, which are
 * no-ops) is appended to a durable JSONL log. The log lives with the other logs
 * (default <repo>/web/log/infojednani-scan/) and is split per calendar day:
 * the file <YYYY-MM-DD>.jsonl is chosen once at launch from the current day
 * (Europe/Prague); a run crossing midnight deliberately keeps writing to the
 * same file (kept simple). The log is append-only (never truncated, survives
 * restarts), so failures can be analysed retroactively (e.g. outage patterns by
 * time of day) and we always know which cells were fetched, when, and which
 * failed and why. One line per cell: {ts, status, court, date, room_idx, room,
 * http, attempts, events|error}. This matters because a cell for a future day
 * that later becomes past can never be backfilled (the API rejects past dates
 * with HTTP 400), so a durable record of what was missed is the only trace left.
 *
 * Run inside the dev container (per project rules — never run php on the host):
 *   docker compose exec -w /var/www/html web php bin/infojednani-scan.php
 *
 * Options (all optional):
 *   --out=<dir>       output directory (default: <repo>/.data/infojednani-scan)
 *   --days=<n>        number of days to scan, starting today (default: 30)
 *   --from=<Y-m-d>    first day to scan (default: today, Europe/Prague)
 *   --delay=<sec>     delay between hearing requests in seconds (default: 10)
 *   --log-dir=<dir>   scan-log directory (default: <repo>/web/log/infojednani-scan)
 */

const API_BASE = 'https://infojednani.gov.cz/api/v1';
const USER_AGENT = 'Lexion (https://lex.ion.cz)';
const CODELIST_DELAY = 1;   // seconds between codelist GETs at startup
const MAX_TRIES = 3;        // attempts per hearing request before giving up
const RETRY_BACKOFF = 5;    // seconds to wait before a retry
const TZ = 'Europe/Prague';

// ---- args ------------------------------------------------------------------

$opts = getopt('', ['out:', 'days:', 'from:', 'delay:', 'log-dir:']);
$repoRoot = dirname(__DIR__);
$outDir = rtrim($opts['out'] ?? $repoRoot . '/.data/infojednani-scan', '/');
$logDir = rtrim($opts['log-dir'] ?? $repoRoot . '/web/log/infojednani-scan', '/');
$days = max(1, (int) ($opts['days'] ?? 30));
$delay = max(0, (int) ($opts['delay'] ?? 10));
$tz = new DateTimeZone(TZ);
try {
    $start = new DateTimeImmutable($opts['from'] ?? 'today', $tz);
} catch (Exception $e) {
    fwrite(STDERR, "Invalid --from date: {$e->getMessage()}\n");
    exit(1);
}

foreach ([$outDir, $logDir] as $needDir) {
    if (!is_dir($needDir) && !mkdir($needDir, 0o777, true) && !is_dir($needDir)) {
        fwrite(STDERR, "Cannot create directory: $needDir\n");
        exit(1);
    }
}

// ---- durable scan log (append-only JSONL, one file per calendar day) --------
// The file is chosen ONCE at launch from the current day (Europe/Prague); a run
// crossing midnight deliberately keeps writing to the same file (kept simple).
// The name is scanner-specific (dir), so future parallel scanners don't clash.
$runDay = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$scanLogFile = $logDir . '/' . $runDay . '.jsonl';
$scanLog = function (array $rec) use ($scanLogFile, $tz): void {
    $line = ['ts' => (new DateTimeImmutable('now', $tz))->format(DateTimeInterface::ATOM)] + $rec;
    file_put_contents(
        $scanLogFile,
        json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND,
    );
};

// ---- http helpers ----------------------------------------------------------

/**
 * @return array{status:int, body:?string, error:string}
 */
function httpRequest(string $method, string $url, ?array $jsonBody = null, int $timeout = 30): array
{
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    return ['status' => $status, 'body' => $body === false ? null : $body, 'error' => $error];
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
    $dates[] = $start->add(new DateInterval("P{$d}D"))->format('Y-m-d');
}
$roomsTotal = array_sum(array_map(static fn(array $c): int => count($c['sine']), $courts));
$total = $roomsTotal * $days;
$width = strlen((string) $total);

$etaHours = ($total * $delay) / 3600;
log_line('');
log_line(sprintf(
    'Plán: %d soudů, %d síní, %d dní (%s … %s) = %s requestů.',
    count($courts), $roomsTotal, $days, $dates[0], $dates[array_key_last($dates)], number_format($total, 0, '', ' '),
));
log_line(sprintf('Odstup %ds → čistý čas skenu ~%.1f h. Výstup: %s', $delay, $etaHours, $outDir));
log_line("Log pokusů (append-only): $scanLogFile");
log_line('Skript je resumovatelný — Ctrl-C a znovu spuštění pokračuje tam, kde skončil.');
log_line('');

$scanLog([
    'event' => 'run_start',
    'from' => $dates[0],
    'to' => $dates[array_key_last($dates)],
    'days' => $days,
    'delay' => $delay,
    'total' => $total,
]);

// ---- scan ------------------------------------------------------------------

$n = 0;
$counts = ['ok' => 0, 'skip' => 0, 'past' => 0, 'fail' => 0, 'events' => 0];

foreach ($courts as $court) {
    $kod = $court['kod'];
    foreach ($dates as $date) {
        foreach ($court['sine'] as $idx => $sin) {
            $n++;
            $counter = sprintf('[%0' . $width . 'd/%d]', $n, $total);

            $dir = "$outDir/$kod/$date";
            $file = sprintf('%s/%03d.json', $dir, $idx);
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
                $scanLog([
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
            $attempts = 0;
            for ($try = 1; $try <= MAX_TRIES; $try++) {
                $attempts = $try;
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

            if ($resp['status'] === 200 && $resp['body'] !== null) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0o777, true);
                }
                writeAtomic($file, $resp['body']);
                $decoded = json_decode($resp['body'], true);
                $events = is_array($decoded['udalosti'] ?? null) ? count($decoded['udalosti']) : 0;
                $counts['ok']++;
                $counts['events'] += $events;
                $scanLog([
                    'status' => 'ok',
                    'court' => $kod,
                    'date' => $date,
                    'room_idx' => $idx,
                    'room' => $sin,
                    'http' => $resp['status'],
                    'attempts' => $attempts,
                    'events' => $events,
                ]);
                log_line("$counter OK   $kod $date #" . sprintf('%03d', $idx)
                    . "  jednání=$events  \"$sin\"");
            } else {
                $counts['fail']++;
                $scanLog([
                    'status' => 'fail',
                    'court' => $kod,
                    'date' => $date,
                    'room_idx' => $idx,
                    'room' => $sin,
                    'http' => $resp['status'],
                    'attempts' => $attempts,
                    'error' => $resp['error'],
                ]);
                log_line("$counter FAIL $kod $date #" . sprintf('%03d', $idx)
                    . "  HTTP {$resp['status']} {$resp['error']} — vzdávám po " . MAX_TRIES . ' pokusech');
            }

            if ($delay > 0) {
                sleep($delay);
            }
        }
    }
}

$scanLog([
    'event' => 'run_end',
    'ok' => $counts['ok'],
    'skip' => $counts['skip'],
    'past' => $counts['past'],
    'fail' => $counts['fail'],
    'events' => $counts['events'],
]);

log_line('');
log_line(sprintf(
    'Hotovo: %d OK, %d přeskočeno, %d v minulosti, %d chyb; celkem nalezeno %d jednání.',
    $counts['ok'], $counts['skip'], $counts['past'], $counts['fail'], $counts['events'],
));
if ($counts['fail'] > 0) {
    log_line('Některé requesty selhaly — spusť skript znovu, doplní jen chybějící soubory.');
}
