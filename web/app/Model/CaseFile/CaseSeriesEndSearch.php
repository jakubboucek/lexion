<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * Pure state machine that finds the end of a case-number series above a known
 * lower bound, with a logarithmic number of probes and no assumption of a
 * contiguous run (holes are tolerated). No DB, no network - drive it by
 * alternating nextProbe() and record(); see docs/navrh-sken-rad.md, "Fáze 2".
 *
 * The caller memoizes probe results, so asking for the same number twice is
 * cheap and this class need not remember every probed value - it tracks only
 * the two moving bounds and one active miss-run:
 *
 *   - `lo`  highest number KNOWN TO EXIST (a hit). NULL = nothing found yet.
 *   - `hi`  lowest number that STARTS A CONFIRMED miss-run beyond the end.
 *           NULL = no upper bracket found yet (still reaching outward).
 *
 * The end is settled once the bracket is tight (hi - lo == 1): then N = lo.
 * A miss is never trusted alone - `confirm` consecutive misses (K) tell the
 * end from a rare interior hole. A hard `ceiling` (the block's `to`) is never
 * crossed; reaching a hit at the ceiling settles the search UNCONFIRMED (the
 * series may continue past a boundary we were told not to cross).
 */
final class CaseSeriesEndSearch
{
    private ?int $lo;
    private ?int $hi = null;

    /** Active contiguous miss-run: anchor number and how many misses seen from it upward. */
    private ?int $confirmAnchor = null;
    private int $confirmLen = 0;

    private bool $estimateProbed = false;
    private int $reachStep;

    private bool $settled = false;
    private bool $confirmed = false;
    private ?int $end = null;
    private ?string $unconfirmedReason = null;


    public function __construct(
        private readonly int $numberFrom,
        ?int $highestKnownHit,
        private readonly ?int $ceiling,
        private readonly int $confirm,
        private readonly ?int $estimate,
    ) {
        if ($this->confirm < 1) {
            throw new \InvalidArgumentException('confirm (K) must be at least 1.');
        }
        $this->lo = $highestKnownHit;
        $base = $highestKnownHit ?? ($numberFrom - 1);
        $span = $estimate !== null && $estimate > $base ? $estimate - $base : 0;
        $this->reachStep = max($this->confirm + 1, 4, (int) ceil(0.05 * $span));
    }


    /** The next number to test, or null once the end is settled. */
    public function nextProbe(): ?SeriesProbe
    {
        if ($this->settled) {
            return null;
        }
        // 1) An active miss-run: keep testing the next contiguous number.
        if ($this->confirmAnchor !== null) {
            return new SeriesProbe($this->confirmAnchor + $this->confirmLen, 'confirm');
        }
        $base = $this->lo ?? ($this->numberFrom - 1);
        // 2) Reaching outward - no upper bracket yet.
        if ($this->hi === null) {
            if ($this->estimate !== null && !$this->estimateProbed && $this->estimate > $base) {
                return new SeriesProbe($this->capToCeiling($this->estimate), 'estimate');
            }
            return new SeriesProbe($this->capToCeiling($base + $this->reachStep), 'gallop');
        }
        // 3) Bisecting the (lo, hi) bracket.
        return new SeriesProbe($base + intdiv($this->hi - $base, 2), 'bisect');
    }


    /** Feeds back the outcome of the number nextProbe() handed out. */
    public function record(int $number, bool $hit): void
    {
        if ($this->settled) {
            return;
        }
        if ($this->estimate !== null && $number >= $this->estimate) {
            $this->estimateProbed = true;
        }

        if ($this->confirmAnchor !== null) {
            $this->recordDuringConfirm($number, $hit);
            return;
        }

        if ($hit) {
            $this->lo = max($this->lo ?? PHP_INT_MIN, $number);
            if ($this->ceiling !== null && $number >= $this->ceiling) {
                $this->settleUnconfirmed('hit_ceiling');
                return;
            }
            if ($this->hi === null) {
                $this->reachStep *= 2; // reach hit -> gallop further
            }
            $this->settleIfBracketTight();
            return;
        }

        // A fresh miss opens a confirmation run anchored here.
        $this->confirmAnchor = $number;
        $this->confirmLen = 1;
        // Enough already (threshold 1), or no room above to confirm (the miss
        // sits at the hard ceiling): the miss itself brackets the end.
        if ($this->confirmLen >= $this->confirmThreshold() || ($this->ceiling !== null && $number >= $this->ceiling)) {
            $this->closeMissRun($number);
        }
    }


    public function isSettled(): bool
    {
        return $this->settled;
    }


    /** The confirmed series end (numberFrom-1 for a confirmed-empty block), or null if unconfirmed. */
    public function confirmedEnd(): ?int
    {
        return $this->confirmed ? $this->end : null;
    }


    /** Why the search settled without a confirmed end (e.g. 'hit_ceiling'), or null. */
    public function unconfirmedReason(): ?string
    {
        return $this->unconfirmedReason;
    }


    /** Highest number known to exist so far (for the decision log). */
    public function lowerBound(): ?int
    {
        return $this->lo;
    }


    /** Lowest number known to be beyond the end so far, or null while still reaching. */
    public function upperBound(): ?int
    {
        return $this->hi;
    }


    /**
     * Best current guess of the series end, for a progress estimate (never a
     * conclusion). Exact once confirmed; while bracketed it is the confirmed
     * lower bound; while still reaching it is the estimate hint or the highest
     * hit, whichever is larger.
     */
    public function bestEndGuess(): int
    {
        if ($this->confirmed && $this->end !== null) {
            return $this->end;
        }
        $lowerBound = $this->lo ?? ($this->numberFrom - 1);
        if ($this->hi !== null) {
            return $lowerBound;
        }
        return max($lowerBound, $this->estimate ?? $lowerBound);
    }


    private function recordDuringConfirm(int $number, bool $hit): void
    {
        if ($hit) {
            // Existence above the miss-run: the run's misses were holes. This
            // hit is the new lower bound; keep reaching from here.
            $this->lo = max($this->lo ?? PHP_INT_MIN, $number);
            $this->confirmAnchor = null;
            $this->confirmLen = 0;
            if ($this->ceiling !== null && $number >= $this->ceiling) {
                $this->settleUnconfirmed('hit_ceiling');
                return;
            }
            if ($this->hi === null) {
                $this->reachStep *= 2;
            }
            $this->settleIfBracketTight();
            return;
        }

        $this->confirmLen++;
        $anchor = $this->confirmAnchor;
        assert($anchor !== null);
        if ($this->confirmLen >= $this->confirmThreshold()) {
            $this->closeMissRun($anchor);
            return;
        }
        // The run reached the hard ceiling before K misses: trust the boundary.
        if ($this->ceiling !== null && $anchor + $this->confirmLen > $this->ceiling) {
            $this->closeMissRun($anchor);
        }
    }


    /** A miss-run at $anchor is beyond the end: tighten the upper bound and check for settle. */
    private function closeMissRun(int $anchor): void
    {
        $this->hi = $this->hi !== null ? min($this->hi, $anchor) : $anchor;
        $this->confirmAnchor = null;
        $this->confirmLen = 0;
        $this->settleIfBracketTight();
    }


    private function settleIfBracketTight(): void
    {
        if ($this->hi === null) {
            return;
        }
        $base = $this->lo ?? ($this->numberFrom - 1);
        if ($this->hi - $base <= 1) {
            $this->settled = true;
            $this->confirmed = true;
            $this->end = $base; // lo, or numberFrom-1 for a confirmed-empty block
        }
    }


    private function settleUnconfirmed(string $reason): void
    {
        $this->settled = true;
        $this->confirmed = false;
        $this->end = null;
        $this->unconfirmedReason = $reason;
    }


    private function capToCeiling(int $number): int
    {
        return $this->ceiling !== null ? min($number, $this->ceiling) : $number;
    }


    /**
     * Consecutive misses needed to bracket the end from above. The full K only
     * while still reaching (no upper bound yet); once `hi` is K-confirmed,
     * tightening it by bisection needs just 2 misses - interior holes are never
     * two in a row, so a pair already proves "beyond end", and the cheaper
     * check roughly thirds the bisect cost of a badly overshot estimate.
     */
    private function confirmThreshold(): int
    {
        return $this->hi === null ? $this->confirm : min($this->confirm, 2);
    }
}
