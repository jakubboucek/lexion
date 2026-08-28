<?php declare(strict_types=1);

use App\Model\CaseFile\CaseSeriesEndSearch;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * Drives the search against an oracle set of existing numbers and returns
 * [confirmedEnd, unconfirmedReason, probeCount]. A guard stops a runaway loop.
 *
 * @param array<int, bool> $exists  number => exists (memoized, like the orchestrator)
 */
function run(
    array $exists,
    int $numberFrom,
    ?int $highestKnownHit,
    ?int $ceiling = null,
    int $confirm = 3,
    ?int $estimate = null,
): array {
    $search = new CaseSeriesEndSearch($numberFrom, $highestKnownHit, $ceiling, $confirm, $estimate);
    $probes = [];
    while (($probe = $search->nextProbe()) !== null) {
        // Distinct network probes only - a memoized repeat costs nothing.
        $probes[$probe->number] = true;
        $hit = $exists[$probe->number] ?? false;
        $search->record($probe->number, $hit);
        if (count($probes) > 200) {
            Assert::fail('probe loop did not converge');
        }
    }
    return [$search->confirmedEnd(), $search->unconfirmedReason(), count($probes)];
}

/** Oracle: a dense series 1..end with the given holes. @return array<int, bool> */
function series(int $end, array $holes = []): array
{
    $exists = [];
    for ($i = 1; $i <= $end; $i++) {
        $exists[$i] = true;
    }
    foreach ($holes as $h) {
        unset($exists[$h]);
    }
    return $exists;
}


test('finds the end just above the known max', function () {
    [$end, $reason, $probes] = run(series(184), numberFrom: 1, highestKnownHit: 180, estimate: 184);
    Assert::same(184, $end);
    Assert::null($reason);
    Assert::true($probes <= 12, "took $probes probes");
});


test('finds the end with no estimate (pure gallop + bisect)', function () {
    [$end, $reason, $probes] = run(series(147), numberFrom: 1, highestKnownHit: 80);
    Assert::same(147, $end);
    Assert::null($reason);
    Assert::true($probes <= 20, "took $probes probes");
});


test('a good estimate is logarithmically cheap even far from the known max', function () {
    [$end, $reason, $probes] = run(series(1747), numberFrom: 1, highestKnownHit: 176, estimate: 1700);
    Assert::same(1747, $end);
    Assert::true($probes <= 20, "took $probes probes");
});


test('overshooting estimate is corrected downward (bisect below the guess)', function () {
    [$end, $reason, $probes] = run(series(120), numberFrom: 1, highestKnownHit: 60, estimate: 300);
    Assert::same(120, $end);
    Assert::null($reason);
    Assert::true($probes <= 20, "took $probes probes");
});


test('a hole just below the end does not stop the search early', function () {
    // 183 exists, 182 is a hole, end is 183.
    [$end] = run(series(183, holes: [182]), numberFrom: 1, highestKnownHit: 180, estimate: 183);
    Assert::same(183, $end);
});


test('a hole exactly at the estimated end is seen through', function () {
    [$end] = run(series(184, holes: [180]), numberFrom: 1, highestKnownHit: 170, estimate: 180);
    Assert::same(184, $end);
});


test('the series ending exactly at the known max is confirmed', function () {
    [$end, $reason] = run(series(80), numberFrom: 1, highestKnownHit: 80, estimate: 80);
    Assert::same(80, $end);
    Assert::null($reason);
});


test('an empty block is confirmed as numberFrom-1', function () {
    [$end, $reason] = run(series(0), numberFrom: 1, highestKnownHit: null);
    Assert::same(0, $end);
    Assert::null($reason);
});


test('a hard ceiling hit leaves the end unconfirmed', function () {
    // The real series reaches 200 but we forbid probing past 150 and 150 exists.
    [$end, $reason] = run(series(200), numberFrom: 1, highestKnownHit: 100, ceiling: 150, estimate: 140);
    Assert::null($end);
    Assert::same('hit_ceiling', $reason);
});


test('a ceiling above the true end still confirms the end', function () {
    // Series ends at 120, ceiling 999 never reached - end is confirmed.
    [$end, $reason] = run(series(120), numberFrom: 1, highestKnownHit: 60, ceiling: 999, estimate: 130);
    Assert::same(120, $end);
    Assert::null($reason);
});


test('an offset block (numberFrom > 1) finds its own end', function () {
    // Block 12001..12028, nothing below 12001 in this scan.
    $exists = [];
    for ($i = 12001; $i <= 12028; $i++) {
        $exists[$i] = true;
    }
    [$end, $reason] = run($exists, numberFrom: 12001, highestKnownHit: 12010, estimate: 12028);
    Assert::same(12028, $end);
    Assert::null($reason);
});


test('K=1 confirms the end on a single miss', function () {
    [$end] = run(series(90), numberFrom: 1, highestKnownHit: 88, confirm: 1, estimate: 90);
    Assert::same(90, $end);
});
