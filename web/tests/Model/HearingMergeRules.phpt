<?php declare(strict_types=1);

/**
 * TEST: HearingMergeRules - the sighting-merge semantics shared by the scan
 * importer and the sync (fresher attributes, room fill-only, entity
 * write-back). Pure entity logic, no DB.
 */

use App\Model\Hearing\Hearing;
use App\Model\Hearing\HearingMergeRules;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


function hearing(
    string $seenAt,
    ?string $room = null,
    ?int $roomId = null,
    ?string $type = null,
    ?string $result = null,
    bool $cancelled = false,
): Hearing
{
    $hearing = new Hearing;
    $hearing->venueCourtKod = 'OSJICCB';
    $hearing->registryNorm = 'C';
    $hearing->senate = 10;
    $hearing->bcNumber = 1;
    $hearing->year = 2026;
    $hearing->hearingDate = new DateTimeImmutable('2026-09-01');
    $hearing->hearingTime = new DateTimeImmutable('0001-01-01 09:00');
    $hearing->room = $room;
    $hearing->roomId = $roomId;
    $hearing->hearingType = $type;
    $hearing->judge = null;
    $hearing->cancelled = $cancelled;
    $hearing->nonPublic = false;
    $hearing->result = $result;
    $hearing->lastSeenAt = new DateTimeImmutable($seenAt);
    return $hearing;
}


test('an older or same-stamp sighting changes nothing', function (): void {
    $local = hearing('2026-08-20 10:00', room: 'A', roomId: 1, result: 'odročeno');
    Assert::null(HearingMergeRules::refreshPatch($local, hearing('2026-08-19 10:00', room: 'A', roomId: 1)));
    Assert::null(HearingMergeRules::refreshPatch($local, hearing('2026-08-20 10:00', room: 'A', roomId: 1)));
});


test('a fresher sighting refreshes the mutable attributes and the stamp', function (): void {
    $local = hearing('2026-08-20 10:00', room: 'A', roomId: 1);
    $patch = HearingMergeRules::refreshPatch(
        $local,
        hearing('2026-08-21 10:00', room: 'A', roomId: 1, type: 'Hlavní líčení', result: 'rozsudek', cancelled: true),
    );
    Assert::notNull($patch);
    Assert::same('Hlavní líčení', $patch->hearingType);
    Assert::same('rozsudek', $patch->result);
    Assert::true($patch->cancelled);
    Assert::same('2026-08-21 10:00', $patch->lastSeenAt->format('Y-m-d H:i'));
    // The room is untouched - the sighting agrees with the stored one.
    Assert::false(isset($patch->room));
});


test('the primary room is filled when missing, even by an older sighting', function (): void {
    $local = hearing('2026-08-20 10:00');
    $patch = HearingMergeRules::refreshPatch($local, hearing('2026-08-19 10:00', room: 'B', roomId: 7));
    Assert::notNull($patch);
    Assert::same('B', $patch->room);
    Assert::same(7, $patch->roomId);
    // Attributes stay: the sighting is older.
    Assert::false(isset($patch->lastSeenAt));
});


test('the primary room is never replaced', function (): void {
    $local = hearing('2026-08-20 10:00', room: 'A', roomId: 1);
    Assert::null(HearingMergeRules::refreshPatch($local, hearing('2026-08-19 10:00', room: 'B', roomId: 7)));
});


test('a missing room id is back-filled when the label matches', function (): void {
    $local = hearing('2026-08-20 10:00', room: 'A');
    $patch = HearingMergeRules::refreshPatch($local, hearing('2026-08-19 10:00', room: 'A', roomId: 4));
    Assert::notNull($patch);
    Assert::same(4, $patch->roomId);
    Assert::false(isset($patch->room));
});


test('applyToEntity carries the patch back onto the stored entity', function (): void {
    $local = hearing('2026-08-20 10:00');
    $incoming = hearing('2026-08-21 10:00', room: 'B', roomId: 7, result: 'smír');
    $patch = HearingMergeRules::refreshPatch($local, $incoming);
    Assert::notNull($patch);
    HearingMergeRules::applyToEntity($local, $patch);
    Assert::same('smír', $local->result);
    Assert::same('B', $local->room);
    Assert::same(7, $local->roomId);
    Assert::same('2026-08-21 10:00', $local->lastSeenAt->format('Y-m-d H:i'));
    // A yet fresher sighting now compares against the updated stamp.
    Assert::null(HearingMergeRules::refreshPatch($local, hearing('2026-08-21 10:00', room: 'B', roomId: 7, result: 'smír')));
});
