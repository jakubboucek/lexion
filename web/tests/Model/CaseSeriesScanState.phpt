<?php declare(strict_types=1);

use App\Model\CaseFile\CaseSeriesEndSearch;
use App\Model\CaseFile\SeriesScanState;
use App\Model\CaseFile\SeriesScanTarget;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * Drives a scan of one block against an oracle and returns the set of numbers
 * that ended up known (held up front, or probed). The invariant a scan must
 * keep: every number from `from` to the end is accounted for - no hole is left
 * unprobed, however the end search jumped over it.
 *
 * @param array<int, bool> $exists     number => exists
 * @param list<int>        $held       numbers already on record
 * @return array{covered: array<int, bool>, end: ?int, hits: int, misses: int}
 */
function drive(array $exists, SeriesScanTarget $target, array $held, int $confirm = 3, ?int $estimate = null): array
{
    $highest = $held !== [] ? max($held) : null;
    $search = new CaseSeriesEndSearch($target->from, $highest, $target->to, $confirm, $estimate ?? $target->estimate);
    $state = new SeriesScanState($target, $search, $held, []);

    $covered = [];
    foreach ($held as $n) {
        $covered[$n] = true;
    }
    $guard = 0;
    while (($work = $state->nextWork()) !== null) {
        $covered[$work->number] = true;
        $state->applyResult($exists[$work->number] ?? false);
        if (++$guard > 5000) {
            Assert::fail('scan did not converge');
        }
    }
    return ['covered' => $covered, 'end' => $search->confirmedEnd(), 'hits' => $state->hits, 'misses' => $state->misses];
}

/** @return array<int, bool> a dense series 1..end minus holes */
function oracle(int $end, array $holes = []): array
{
    $e = [];
    for ($i = 1; $i <= $end; $i++) {
        $e[$i] = true;
    }
    foreach ($holes as $h) {
        unset($e[$h]);
    }
    return $e;
}

/** Asserts every number in [from, end] is covered. */
function assertGapless(array $covered, int $from, int $end): void
{
    for ($n = $from; $n <= $end; $n++) {
        Assert::true($covered[$n] ?? false, "number $n was left unprobed");
    }
}


test('a hole above the initial max is still filled (end-search jumped it)', function () {
    // We hold only #10; 11 is a hole, the series ends at 12. End-search jumps
    // from 10 toward the estimate and could skip 11 - the tail must catch it.
    $target = new SeriesScanTarget('X', 'T', 10, 2025, estimate: 19);
    $r = drive(oracle(12, holes: [11]), $target, held: [10]);
    Assert::same(12, $r['end']);
    assertGapless($r['covered'], 1, 12);
});


test('full closed series with scattered holes is covered end to end', function () {
    $target = new SeriesScanTarget('X', 'T', 5, 2025);
    $holes = [3, 40, 41, 77]; // interior singletons + a start-of-year pair
    $r = drive(oracle(120, $holes), $target, held: [8, 60, 119]);
    Assert::same(120, $r['end']);
    assertGapless($r['covered'], 1, 120);
});


test('an offset block is covered across its own range', function () {
    $exists = [];
    for ($i = 12001; $i <= 12028; $i++) {
        $exists[$i] = true;
    }
    unset($exists[12015]); // a hole inside the block
    $target = new SeriesScanTarget('X', 'NC-LIKE', 12, 2026, from: 12001, to: 12999, estimate: 12028);
    // registry here is only a label in the target; the state machine is generic.
    $r = drive($exists, $target, held: [12001]);
    assertGapless($r['covered'], 12001, 12028);
});


test('holding nothing still discovers and fills the whole series', function () {
    $target = new SeriesScanTarget('X', 'T', 1, 2025);
    $r = drive(oracle(45), $target, held: []);
    Assert::same(45, $r['end']);
    assertGapless($r['covered'], 1, 45);
});
