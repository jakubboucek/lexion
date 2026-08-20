<?php declare(strict_types=1);

/**
 * Fetches the hearing (NAR_JED/ZRUS_JED) event details of one or more cases from
 * infosoud and persists everything into the cache: the case itself and its event
 * projections via CaseFileSyncService, then each hearing event's detail into
 * case_file_event.detail_json (the same write the web detail does lazily).
 *
 * Purpose: infoSoud is the corroborating source for hearings (it reaches into the
 * past and far future and carries JED_* metadata infoJednani lacks). This tool
 * makes those details available offline for analysis and comparison against the
 * infoJednani scan (see docs/infojednani-api.md).
 *
 * Run inside the dev container:
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch-hearings.php OSVYCTU "6 C 1/2023"
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch-hearings.php --list=cases.txt
 *
 * The list file holds one "<court_kod> <spisovka>" per line (# starts a comment).
 *
 * Options:
 *   --list=<file>   read cases from a file instead of argv
 *   --delay=<sec>   delay between infosoud requests (default: 3)
 */

use App\Bootstrap;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileEventRepository;
use App\Model\CaseFile\CaseFileSyncService;
use App\Model\CaseFile\EventDetailOutcome;
use App\Model\CaseFile\EventDetailService;
use App\Model\CaseFile\StoredJson;
use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudHearing;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['list:', 'delay:']);
$delay = max(0, (int) ($opts['delay'] ?? 3));

/** @var list<array{0:string,1:string}> $cases */
$cases = [];
if (isset($opts['list'])) {
    // getopt() hands back an array for a repeated option and false for a
    // flag - only a plain string is a usable value.
    $listFile = is_string($opts['list']) ? $opts['list'] : null;
    $lines = $listFile !== null ? @file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if ($lines === false) {
        fwrite(STDERR, 'Cannot read list file: ' . ($listFile ?? '(invalid --list value)') . "\n");
        exit(1);
    }
    foreach ($lines as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '') {
            continue;
        }
        [$kod, $spisovka] = explode(' ', $line, 2) + [null, null];
        if ($kod === null || $spisovka === null) {
            fwrite(STDERR, "Skipping malformed line: $line\n");
            continue;
        }
        $cases[] = [$kod, trim($spisovka)];
    }
} else {
    $argvCases = array_slice($argv, 1);
    if (count($argvCases) < 2) {
        fwrite(STDERR, "Usage: php bin/infosoud-fetch-hearings.php <court_kod> \"<spisovka>\"\n");
        fwrite(STDERR, "       php bin/infosoud-fetch-hearings.php --list=<file>\n");
        exit(1);
    }
    $cases[] = [$argvCases[0], $argvCases[1]];
}

$container = (new Bootstrap)->bootConsoleApplication();
$courts = $container->getByType(CourtRepository::class);
$parser = $container->getByType(SpisovkaParser::class);
$sync = $container->getByType(CaseFileSyncService::class);
$events = $container->getByType(CaseFileEventRepository::class);
$eventDetails = $container->getByType(EventDetailService::class);

const HEARING_CODES = ['NAR_JED', 'ZRUS_JED'];

foreach ($cases as $i => [$kod, $spisovkaText]) {
    echo str_repeat('=', 72) . "\n";
    printf("[%d/%d] %s %s\n", $i + 1, count($cases), $kod, $spisovkaText);

    $court = $courts->getByKod(strtoupper($kod));
    if ($court === null) {
        echo "  ! unknown court\n";
        continue;
    }
    try {
        $spisovka = $parser->parse($spisovkaText);
    } catch (SpisovkaParseException $e) {
        echo "  ! cannot parse spisovka: {$e->getMessage()}\n";
        continue;
    }

    try {
        $row = $sync->refreshFromInfosoud($court, $spisovka);
    } catch (InfosoudApiException $e) {
        echo "  ! infosoud error: {$e->getMessage()}\n";
        continue;
    }
    if ($row === null) {
        echo "  ! case not found on infosoud\n";
        sleep($delay);
        continue;
    }
    echo "  case on record (case file #{$row->id})\n";
    sleep($delay);

    $hearings = array_filter(
        $events->findByCaseFile((int) $row->id),
        static fn(CaseFileEvent $event): bool => in_array($event->eventCode, HEARING_CODES, true),
    );
    if ($hearings === []) {
        echo "  (no NAR_JED/ZRUS_JED events in the timeline)\n";
        continue;
    }

    foreach ($hearings as $event) {
        $label = sprintf(
            '%s #%s %s',
            $event->eventCode,
            $event->eventOrder ?? '?',
            $event->eventDate?->format('Y-m-d') ?? '-',
        );

        // Foreign events (ref_*) address another case's sequence; skip them
        // here - the point of this tool is the case's own hearings.
        if ($event->refRegistryNorm !== null) {
            echo "  $label — foreign event, skipping\n";
            continue;
        }

        // Same fetch, same integrity guard as the web detail - one owner.
        $result = $eventDetails->fetch($event, $court, $spisovka);
        $event = $result->event;
        switch ($result->outcome) {
            case EventDetailOutcome::Fetched:
                echo "  $label — detail fetched and persisted\n";
                sleep($delay);
                break;
            case EventDetailOutcome::NoDetail:
                echo "  $label — upstream has no detail\n";
                sleep($delay);
                break;
            case EventDetailOutcome::AlreadyFetched:
                echo "  $label — " . ($event->detailJson !== null
                    ? "detail already cached\n"
                    : "upstream has no detail (known)\n");
                break;
            case EventDetailOutcome::NotAddressable:
                echo "  $label — no upstream address (no poradi)\n";
                break;
            case EventDetailOutcome::Unavailable:
                echo "  $label — infosoud unavailable\n";
                sleep($delay);
                break;
            case EventDetailOutcome::IntegrityBroken:
                echo "  $label — ! integrity mismatch (upstream renumbered), not persisted\n";
                sleep($delay);
                break;
        }

        if ($event->detailJson === null) {
            continue;
        }
        $detail = StoredJson::decode($event->detailJson, "event #{$event->id} (detail_json)");
        // Dump the JED_* attributes verbatim: this is what we compare against
        // the infoJednani room labels.
        foreach (is_array($detail['atributy'] ?? null) ? $detail['atributy'] : [] as $attribute) {
            $type = (string) ($attribute['typ'] ?? '');
            if (str_starts_with($type, 'JED_')) {
                printf("      %-12s = %s\n", $type, trim((string) ($attribute['hodnota'] ?? '')));
            }
        }
        $hearing = InfosoudHearing::fromEventDetail($detail);
        if ($hearing !== null) {
            printf(
                "      → parsed: startsAt=%s room=%s type=%s result=%s cancelled=%s\n",
                $hearing->startsAt?->format('Y-m-d H:i') ?? '-',
                $hearing->room ?? '-',
                $hearing->type ?? '-',
                $hearing->result ?? '-',
                $hearing->cancelled ? 'yes' : 'no',
            );
        }
    }
}
