<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudRejectedException;
use App\Model\Log\LogRunChannel;
use App\Model\Log\LogRunJsonlFile;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
use App\Model\Spisovka\SpisovkaFactory;


/**
 * Adaptive scan of case-number series (docs/navrh-sken-rad.md): fills each
 * block's holes and finds its end with a logarithmic number of probes, then
 * records the confirmed end in `case_series_scan`. Every probe already lands in
 * case_file (hit) or case_lookup_miss (miss) through CaseFileSyncService, so
 * this service adds the scheduling, the end-search and the decision log.
 *
 * Registries whose numbering is not a dense per-senate series are refused
 * outright - a dense 1..N scan would burn tens of thousands of requests on the
 * gaps (see the doc's registry analysis).
 */
final readonly class CaseSeriesScanService
{
    private const array BlockedRegistries = ['INS', 'EPR', 'ICM', 'EXE', 'NT', 'NC'];

    public function __construct(
        private CaseFileSyncService $sync,
        private CaseFileRepository $caseFiles,
        private CaseLookupMissRepository $misses,
        private CaseSeriesScanRepository $scans,
        private CourtRepository $courts,
        private SpisovkaFactory $spisovkaFactory,
        private CaseFileEventRepository $events,
        private EventDetailService $eventDetails,
        private CaseFileProjectionService $projection,
        private LogService $log,
    ) {
    }


    /**
     * @param list<SeriesScanTarget> $targets
     * @param callable(string):void $progress line sink for the CLI
     * @return list<SeriesScanResult>
     */
    public function scan(
        array $targets,
        int $delaySeconds,
        bool $dryRun,
        ?int $maxRequests,
        int $confirm,
        bool $fetchFirstEventDetail,
        callable $progress,
    ): array
    {
        $states = [];
        foreach ($targets as $target) {
            $this->assertScannable($target);
            $states[] = $this->buildState($target, $confirm, $progress);
        }

        $estimatedWork = 0;
        foreach ($states as $state) {
            $estimatedWork += $state->estimatedRemaining();
        }
        $progress(sprintf(
            'Odhad práce: ~%d sond (%d řad)%s',
            $estimatedWork, count($states),
            $fetchFirstEventDetail ? ', s detailem 1. události (hit = 2 requesty)' : ', bez detailu události',
        ));

        if ($dryRun) {
            return $this->reportPlan($states, $progress);
        }

        return $this->run($states, $delaySeconds, $maxRequests, $fetchFirstEventDetail, $progress);
    }


    private function assertScannable(SeriesScanTarget $target): void
    {
        if (in_array($target->registryNorm, self::BlockedRegistries, true)) {
            throw new \InvalidArgumentException(
                "Registry {$target->registryNorm} is not a dense per-senate series and cannot be scanned "
                . '(see docs/navrh-sken-rad.md).',
            );
        }
        if ($this->courts->getByKod($target->courtKod) === null) {
            throw new \InvalidArgumentException("Unknown court: {$target->courtKod}");
        }
    }


    /** @param callable(string):void $progress */
    private function buildState(SeriesScanTarget $target, int $confirm, callable $progress): SeriesScanState
    {
        $held = $this->caseFiles->numbersInSeries(
            $target->courtKod, $target->registryNorm, $target->senate, $target->year, $target->from, $target->to,
        );
        $documentedMisses = $this->misses->notFoundNumbersInSeries(
            $target->courtKod, $target->registryNorm, $target->senate, $target->year, $target->from, $target->to,
        );
        $highestHit = $held !== [] ? max($held) : null;
        $estimate = $target->estimate ?? $this->estimate($held);
        $endSearch = new CaseSeriesEndSearch($target->from, $highestHit, $target->to, $confirm, $estimate);

        $progress(sprintf(
            '· %s: %d held, %d holes recorded, M=%s, estimate=%s',
            $target->label(), count($held), count($documentedMisses),
            $highestHit ?? '-', $estimate ?? '-',
        ));
        return new SeriesScanState($target, $endSearch, $held, $documentedMisses);
    }


    /**
     * A no-request end estimate: the German-tank estimator N ≈ m + m/k − 1 over
     * the numbers we hold. Only a hint for the first reach probe; gallop and
     * bisect correct any error. Null when we hold nothing.
     *
     * @param list<int> $held
     */
    private function estimate(array $held): ?int
    {
        $k = count($held);
        if ($k === 0) {
            return null;
        }
        $m = max($held);
        return $m + intdiv($m, $k) - 1;
    }


    /**
     * @param list<SeriesScanState> $states
     * @param callable(string):void $progress
     * @return list<SeriesScanResult>
     */
    private function reportPlan(array $states, callable $progress): array
    {
        $results = [];
        foreach ($states as $state) {
            $progress(sprintf('  %s: dry-run, no requests sent', $state->target->label()));
            $results[] = new SeriesScanResult($state->target->label(), null, 'dry_run', 0, 0, 0);
        }
        return $results;
    }


    /**
     * @param list<SeriesScanState> $states
     * @param callable(string):void $progress
     * @return list<SeriesScanResult>
     */
    private function run(array $states, int $delaySeconds, ?int $maxRequests, bool $fetchFirstEventDetail, callable $progress): array
    {
        $session = $this->log->buildRunSession(CaseFileLogKind::SeriesScan, data: [
            'series' => array_map(static fn(SeriesScanState $s) => $s->target->label(), $states),
            'maxRequests' => $maxRequests,
        ]);
        $decisions = $session->jsonlFile(LogRunChannel::Out);
        $run = $session->start();

        $active = $states;
        $requests = 0;
        $seq = 0;
        $interrupted = false;
        try {
            while ($active !== []) {
                if ($maxRequests !== null && $requests >= $maxRequests) {
                    $interrupted = true;
                    break;
                }
                $i = array_rand($active);
                $state = $active[$i];
                $work = $state->nextWork();
                if ($work === null) {
                    $this->recordScan($state, decisions: $decisions);
                    $progress($this->summaryLine($state));
                    unset($active[$i]);
                    $active = array_values($active);
                    continue;
                }

                $cached = $state->isKnown($work->number);
                if ($cached) {
                    $hit = $state->knownResult($work->number);
                } else {
                    $hit = $this->fetch($state, $work->number, $fetchFirstEventDetail, $delaySeconds);
                    $requests++;
                    $state->requests++;
                    // One line per real request (cached probes stay silent) so
                    // a long run shows life on stdout, like infosoud-fetch. The
                    // total is an estimate (~): requests done plus the unknown
                    // numbers still ahead across the active blocks, re-summed
                    // each time so it tracks the end search learning the range.
                    $remaining = 0;
                    foreach ($active as $s) {
                        $remaining += $s->estimatedRemaining();
                    }
                    $progress(sprintf(
                        '  [%d/~%d] %s [%s] %s',
                        $requests, $requests + $remaining,
                        $state->target->caseLabel($work->number), $work->method, $hit ? 'spis' : '—',
                    ));
                }
                $state->applyResult($hit);
                $decisions->write([
                    'seq' => ++$seq,
                    'series' => $state->target->label(),
                    'number' => $work->number,
                    'phase' => $work->phase,
                    'method' => $work->method,
                    'result' => $hit ? 'hit' : 'miss',
                    'cached' => $cached,
                    'lo' => $state->endSearch()->lowerBound(),
                    'hi' => $state->endSearch()->upperBound(),
                ]);
                if (!$cached && $delaySeconds > 0) {
                    sleep($delaySeconds);
                }
            }
        } catch (InfosoudRejectedException $e) {
            $run->finish(LogStatus::Failed, result: 'refused', message: $e->getMessage());
            throw $e;
        } catch (InfosoudApiException $e) {
            $run->finish(LogStatus::Failed, result: 'outage', message: $e->getMessage());
            throw $e;
        }

        // Blocks left active when max-requests fired: record scanned_at only.
        $results = [];
        foreach ($states as $state) {
            if (!$state->isDone()) {
                $this->recordScan($state, interrupted: true, decisions: $decisions);
            }
            $results[] = $this->resultOf($state, $interrupted);
        }
        $run->finish(
            $interrupted ? LogStatus::Failed : LogStatus::Ok,
            result: $interrupted ? 'max_requests' : 'ok',
            resultData: ['requests' => $requests, 'series' => array_map(static fn(SeriesScanResult $r) => $r->toLogData(), $results)],
        );
        return $results;
    }


    /**
     * Fetches one case's overview and reports the hit. When $wantDetail is on
     * and the overview found a case, the first own event's detail is fetched as
     * a SECOND request - the same two-step infosoud-fetch uses, through the one
     * service that owns detail requests (EventDetailService), then reprojected
     * so the subject and the PRED_VEC relation are materialized. A miss fetches
     * no detail. The two upstream requests are spaced by $delaySeconds to stay
     * gentle; the caller adds one more delay after this returns.
     *
     * @throws InfosoudApiException
     */
    private function fetch(SeriesScanState $state, int $number, bool $wantDetail, int $delaySeconds): bool
    {
        $t = $state->target;
        $spisovka = $this->spisovkaFactory->fromCase($t->senate, $t->registryNorm, $number, $t->year);
        $court = $this->courts->getByKod($t->courtKod);
        assert($court !== null); // validated in assertScannable()
        $stored = $this->sync->refreshFromInfosoud($court, $spisovka, fetchFirstEventDetail: false);
        if ($stored === null) {
            return false;
        }
        if ($wantDetail) {
            // A scanned case is brand new, so its first own event never has a
            // detail yet - no freshness or renumbering guard needed here.
            $owed = CaseSummaryExtraction::firstOwn($this->events->findByCaseFile($stored->id));
            if ($owed !== null && $owed->detailFetchedAt === null) {
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
                $result = $this->eventDetails->fetch($owed, $court, $spisovka);
                if ($result->outcome === EventDetailOutcome::Fetched) {
                    // The subject is settled by the fetch; PRED_VEC is projected
                    // from the detail - reproject so the case is left whole.
                    $this->projection->projectInfosoud($stored);
                }
            }
        }
        return true;
    }


    private function recordScan(SeriesScanState $state, bool $interrupted = false, ?LogRunJsonlFile $decisions = null): void
    {
        $t = $state->target;
        $confirmedEnd = $interrupted ? null : $state->endSearch()->confirmedEnd();
        $this->scans->record(
            $t->courtKod, $t->registryNorm, $t->senate, $t->year, $t->from, $confirmedEnd, new \DateTimeImmutable,
        );
        $decisions?->write([
            'series' => $t->label(),
            'event' => 'end',
            'confirmed_end' => $confirmedEnd,
            'reason' => $interrupted ? 'interrupted' : $state->endSearch()->unconfirmedReason(),
            'hits' => $state->hits,
            'misses' => $state->misses,
            'requests' => $state->requests,
        ]);
    }


    private function resultOf(SeriesScanState $state, bool $interrupted): SeriesScanResult
    {
        $done = $state->isDone();
        $confirmedEnd = $done ? $state->endSearch()->confirmedEnd() : null;
        $reason = $confirmedEnd !== null
            ? null
            : ($done ? $state->endSearch()->unconfirmedReason() : 'interrupted');
        return new SeriesScanResult(
            $state->target->label(), $confirmedEnd, $reason, $state->requests, $state->hits, $state->misses,
        );
    }


    private function summaryLine(SeriesScanState $state): string
    {
        $end = $state->endSearch()->confirmedEnd();
        $verdict = $end !== null
            ? "konec $end"
            : 'konec nepotvrzen (' . ($state->endSearch()->unconfirmedReason() ?? '?') . ')';
        return sprintf(
            '✓ %s: %s | %d hitů, %d missů, %d sond',
            $state->target->label(), $verdict, $state->hits, $state->misses, $state->requests,
        );
    }
}
