<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * Mutable per-block progress of a running series scan: the memoized results
 * (so a repeated probe costs nothing), the end-search state machine, and the
 * bulk-fill queues. The scheduler pulls one unit at a time with nextWork() and
 * reports the outcome with applyResult(); a plain object, created by
 * CaseSeriesScanService, not a service.
 *
 * Two bulk queues keep a single-block run from looking sequential: holes in the
 * already-known range [from, M] are fillable at once and interleave with the
 * end-search probes; the tail (M, end] is enqueued only after the end settles.
 * Both are shuffled, so no ascending run appears in the provider's log.
 */
final class SeriesScanState
{
    /** @var array<int, bool> number => exists (memoized across the run) */
    private array $known = [];

    /** @var list<int> holes to fill in [from, M], shuffled */
    private array $bulk;

    private bool $tailBuilt = false;
    private bool $done = false;

    private ?SeriesScanWork $pending = null;

    public int $requests = 0;
    public int $hits = 0;
    public int $misses = 0;

    /** Highest held number at construction - the boundary between the two bulk queues. */
    private readonly int $initialMax;


    /**
     * @param list<int> $heldNumbers    case numbers already on record in [from, to]
     * @param list<int> $documentedMisses not_found numbers already recorded in [from, to]
     */
    public function __construct(
        public readonly SeriesScanTarget $target,
        private readonly CaseSeriesEndSearch $endSearch,
        array $heldNumbers,
        array $documentedMisses,
    ) {
        foreach ($heldNumbers as $n) {
            $this->known[$n] = true;
        }
        foreach ($documentedMisses as $n) {
            $this->known[$n] = false;
        }
        $this->initialMax = $heldNumbers !== [] ? max($heldNumbers) : $target->from - 1;
        $this->bulk = $this->unknownRange($target->from, $this->initialMax);
        shuffle($this->bulk);
    }


    /** The next unit to process, or null when this block is finished. */
    public function nextWork(): ?SeriesScanWork
    {
        if ($this->done) {
            return null;
        }
        if ($this->endSearch->isSettled() && !$this->tailBuilt) {
            $this->buildTail();
        }

        $hasBulk = $this->bulk !== [];
        $endProbe = $this->endSearch->isSettled() ? null : $this->endSearch->nextProbe();

        if (!$hasBulk && $endProbe === null) {
            $this->done = true;
            return $this->pending = null;
        }
        // Mix the two streams: when both have work, pick at random so bulk and
        // end-search probes interleave rather than running in two blocks.
        $useEnd = $endProbe !== null && (!$hasBulk || random_int(0, 1) === 1);
        if ($endProbe !== null && $useEnd) {
            return $this->pending = new SeriesScanWork($endProbe->number, 'end_search', $endProbe->method);
        }
        $number = (int) array_pop($this->bulk);
        return $this->pending = new SeriesScanWork($number, 'bulk_fill', 'plan');
    }


    /** Reports the outcome of the unit last handed out by nextWork(). */
    public function applyResult(bool $hit): void
    {
        $work = $this->pending;
        if ($work === null) {
            throw new \LogicException('applyResult() without a pending unit.');
        }
        $this->pending = null;
        $this->known[$work->number] = $hit;
        $hit ? $this->hits++ : $this->misses++;
        if ($work->phase === 'end_search') {
            $this->endSearch->record($work->number, $hit);
        }
    }


    /** True once nothing remains - the block is fully filled and its end settled. */
    public function isDone(): bool
    {
        return $this->done;
    }


    public function endSearch(): CaseSeriesEndSearch
    {
        return $this->endSearch;
    }


    /**
     * Rough count of real requests still ahead for this block: the unknown
     * numbers up to the current best end guess, plus a small allowance for the
     * end search while it is still running. Self-correcting - the guess grows
     * as reaching finds higher hits and the known set grows as probes land,
     * so the caller's `~total` tracks reality rather than the first estimate.
     */
    public function estimatedRemaining(): int
    {
        $guess = $this->endSearch->bestEndGuess();
        $size = max(0, $guess - $this->target->from + 1);
        $knownUpTo = 0;
        foreach ($this->known as $number => $_) {
            if ($number >= $this->target->from && $number <= $guess) {
                $knownUpTo++;
            }
        }
        $allowance = $this->endSearch->isSettled() ? 0 : 4;
        return max(0, $size - $knownUpTo) + $allowance;
    }


    /** True if a probe number is already known locally (no network needed). */
    public function isKnown(int $number): bool
    {
        return array_key_exists($number, $this->known);
    }


    public function knownResult(int $number): bool
    {
        return $this->known[$number];
    }


    /** The tail (M, end] becomes fillable only once the end is known. */
    private function buildTail(): void
    {
        $this->tailBuilt = true;
        $confirmed = $this->endSearch->confirmedEnd();
        // Confirmed end, else fill up to the hard ceiling / highest hit found.
        $end = $confirmed
            ?? $this->target->to
            ?? $this->endSearch->lowerBound()
            ?? ($this->target->from - 1);
        // The tail is (M_original, end] - end-search may have skipped holes in
        // there (jumping past them), so it starts above the initial held max,
        // NOT above the grown known max. unknownRange() drops what is already
        // known (held, documented, or probed during the end search).
        $tail = $this->unknownRange($this->initialMax + 1, $end);
        shuffle($tail);
        // Append so the remaining known-range holes still get their turn too.
        $this->bulk = [...$this->bulk, ...$tail];
    }


    /** @return list<int> numbers in [lo, hi] not yet known locally */
    private function unknownRange(int $lo, int $hi): array
    {
        $out = [];
        for ($n = max($lo, $this->target->from); $n <= $hi; $n++) {
            if (!array_key_exists($n, $this->known)) {
                $out[] = $n;
            }
        }
        return $out;
    }
}
