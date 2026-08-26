<?php declare(strict_types=1);

/**
 * Fetches one or more cases from the infosoud API into the case file records.
 * Runs the same services as the web detail, so it also fetches the first own
 * event detail and keeps the event/relation projections in step. Run inside
 * the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch.php OSVYCTU "6 C 1/2023"
 *   docker compose exec -w /var/www/html web php bin/infosoud-fetch.php --list=cases.txt
 *
 * The list file holds one "<court_kod> <spisovka>" per line (# starts a comment).
 *
 * WHAT GETS FETCHED is a list of artifacts, not one all-or-nothing decision:
 * the case overview, and - unless --no-first-event says otherwise - the detail
 * of the case's first own event. --skip-fresh is judged SEPARATELY for each of
 * them, so a case whose overview is fresh but whose first event was never
 * fetched still gets that one request. A detail that was never fetched is
 * never fresh, however recently its timeline row was written. Further
 * artifacts (upcoming hearings, ...) are meant to slot in as more steps under
 * the same rule.
 *
 * Options:
 *   --list=<file>        read cases from a file instead of argv
 *   --delay=<sec>        delay between infosoud requests (default: 1)
 *   --skip-fresh=<days>  skip an artifact fetched within the last <days> days
 *   --no-first-event     do not fetch the first event detail at all
 */

use App\Bootstrap;
use App\Model\CaseFile\CaseFile;
use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileEventRepository;
use App\Model\CaseFile\CaseFileProjectionService;
use App\Model\CaseFile\CaseFileRepository;
use App\Model\CaseFile\CaseFileSyncService;
use App\Model\CaseFile\CaseSummaryExtraction;
use App\Model\CaseFile\EventDetailOutcome;
use App\Model\CaseFile\EventDetailService;

use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;

use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['list:', 'delay:', 'skip-fresh:', 'no-first-event', 'shuffle']);
$delay = max(0, (int) ($opts['delay'] ?? 1));
$freshSince = isset($opts['skip-fresh'])
    ? new DateTimeImmutable('-' . max(0, (int) $opts['skip-fresh']) . ' days')
    : null;
$wantFirstEvent = !isset($opts['no-first-event']);

/**
 * Was this artifact fetched recently enough to leave alone? Never fetched
 * (null) is never fresh, and without --skip-fresh nothing is.
 */
$isFresh = static fn(?DateTimeImmutable $fetchedAt): bool =>
    $freshSince !== null && $fetchedAt !== null && $fetchedAt >= $freshSince;

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
    if(isset($opts['shuffle'])) {
        shuffle($lines);
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
    // getopt() leaves the parsed options in $argv - drop them.
    $argvCases = array_values(array_filter(
        array_slice($argv, 1),
        static fn(string $arg): bool => !str_starts_with($arg, '--'),
    ));
    if (count($argvCases) < 2) {
        fwrite(STDERR, "Usage: php bin/infosoud-fetch.php <court_kod> \"<spisovka>\"\n");
        fwrite(STDERR, "       php bin/infosoud-fetch.php --list=<file>\n");
        exit(1);
    }
    $cases[] = [$argvCases[0], $argvCases[1]];
}

$container = (new Bootstrap)->bootConsoleApplication();
$courts = $container->getByType(CourtRepository::class);
$parser = $container->getByType(SpisovkaParser::class);
$sync = $container->getByType(CaseFileSyncService::class);
$caseFiles = $container->getByType(CaseFileRepository::class);
$events = $container->getByType(CaseFileEventRepository::class);
$eventDetails = $container->getByType(EventDetailService::class);
$projection = $container->getByType(CaseFileProjectionService::class);

/**
 * The first own record still owing us its detail, or null when there is
 * nothing to fetch (no such record, or its detail is fresh enough).
 */
$pendingFirstEvent = static function (CaseFile $case) use ($events, $isFresh): ?CaseFileEvent {
    $first = CaseSummaryExtraction::firstOwn($events->findByCaseFile($case->id));
    return $first !== null && !$isFresh($first->detailFetchedAt) ? $first : null;
};

$total = count($cases);
$stats = ['updated' => 0, 'inserted' => 0, 'fresh' => 0, 'notFound' => 0, 'failed' => 0];

foreach ($cases as $i => [$kod, $spisovkaText]) {
    $position = $total > 1 ? sprintf('[%d/%d] ', $i + 1, $total) : '';

    $court = $courts->getByKod(strtoupper($kod));
    if ($court === null) {
        echo $position . "! unknown court: $kod\n";
        $stats['failed']++;
        continue;
    }
    try {
        $spisovka = $parser->parse($spisovkaText);
    } catch (SpisovkaParseException $e) {
        echo $position . "! cannot parse spisovka \"$spisovkaText\": {$e->getMessage()}\n";
        $stats['failed']++;
        continue;
    }

    // Name the case BEFORE any network or database work and flush it out: a
    // run that dies mid-case has to leave the case it died on on screen. The
    // outcome completes this very line.
    printf('%s%s @ %s ... ', $position, $spisovka->format(), $court->name);
    flush();

    $existing = $caseFiles->getByCase((string) $court->kod, $spisovka);
    $overviewFresh = $isFresh($existing?->infosoudAt);
    // A fresh overview says nothing about the detail we may still owe.
    $pending = $wantFirstEvent && $existing !== null ? $pendingFirstEvent($existing) : null;

    if ($overviewFresh && $pending === null) {
        // Nothing was fetched, so no delay either.
        printf("FRESH | infosoud data from %s\n",
            $existing?->infosoudAt?->format('Y-m-d H:i') ?? '-',
        );
        $stats['fresh']++;
        continue;
    }

    // Step 1: the case overview. Skipped when it is fresh - the detail below
    // is judged on its own and may still need fetching.
    $stored = $existing;
    $requests = 0;
    if (!$overviewFresh) {
        try {
            // Deliberately WITHOUT the detail: every event detail is fetched by
            // step 2, through the one service that owns that request. Paying
            // one extra reprojection is worth having a single path per artifact.
            $stored = $sync->refreshFromInfosoud($court, $spisovka, fetchFirstEventDetail: false);
        } catch (InfosoudApiException $e) {
            echo "! infosoud error: {$e->getMessage()}\n";
            $stats['failed']++;
            sleep($delay);
            continue;
        }
        if ($stored === null) {
            echo "NOT FOUND\n";
            $stats['notFound']++;
            sleep($delay);
            continue;
        }
        $requests++;
    }

    // Step 2: the detail of the first own event. Asked for the state AFTER the
    // refresh - a moved timeline can put a different record first.
    $detailNote = '';
    if ($wantFirstEvent && $stored !== null && ($owed = $pendingFirstEvent($stored)) !== null) {
        if ($requests > 0) {
            sleep($delay);
        }
        // An expired detail is re-asked; refetch is what tells the service the
        // row's existing detail is not an answer.
        $result = $eventDetails->fetch($owed, $court, $spisovka, refetch: $owed->detailFetchedAt !== null);
        $detailNote = match ($result->outcome) {
            EventDetailOutcome::Fetched => ' | detail 1. udalosti stazen',
            EventDetailOutcome::NoDetail => ' | 1. udalost detail nema',
            EventDetailOutcome::NotAddressable => ' | 1. udalost nelze adresovat',
            EventDetailOutcome::Unavailable => ' | ! detail 1. udalosti nedostupny',
            EventDetailOutcome::IntegrityBroken => ' | ! detail 1. udalosti odmitnut (nesoulad)',
            EventDetailOutcome::AlreadyFetched => '',
        };
        if ($result->outcome === EventDetailOutcome::Fetched) {
            // The subject is settled by the fetch itself, but the PRED_VEC
            // relation is projected from that detail - reproject so the case is
            // left whole however the detail arrived.
            $projection->projectInfosoud($stored);
            $stored = $caseFiles->getByCase((string) $court->kod, $spisovka) ?? $stored;
        }
    }

    if ($stored === null) {
        continue; // cannot happen: an untouched case is either stored or fresh
    }
    printf("%s | stav: %s | predmet: %s%s\n",
        $existing === null ? 'INSERTED' : 'UPDATED',
        $stored->status ?? '-',
        $stored->subject ?? '-',
        $detailNote,
    );
    $stats[$existing === null ? 'inserted' : 'updated']++;
    if ($i + 1 < $total) {
        sleep($delay);
    }
}

if ($total > 1) {
    printf(
        "\nDone: %d updated, %d inserted, %d skipped fresh, %d not found, %d failed (of %d)\n",
        $stats['updated'],
        $stats['inserted'],
        $stats['fresh'],
        $stats['notFound'],
        $stats['failed'],
        $total,
    );
    if ($stats['notFound'] > 0 || $stats['failed'] > 0) {
        exit(1);
    }
}
