<?php declare(strict_types=1);

use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseSummaryExtraction;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** @param list<array{typ: string, hodnota: ?string}> $attributes */
function eventDetail(array $attributes): array
{
    return ['typUdalosti' => 'ZAHAJ_RIZ', 'datumUdalost' => '08.07.2026', 'atributy' => $attributes];
}


/**
 * An explicit null (the column gets cleared) as opposed to an untouched
 * property - isset() cannot tell the two apart on a typed property.
 */
function isExplicit(object $entity, string $property): bool
{
    return (new ReflectionProperty($entity, $property))->isInitialized($entity);
}


function storedEvent(
    string $code,
    ?string $date,
    ?int $order,
    ?string $detail,
    bool $foreign = false,
    ?int $parentOrder = null,
): CaseFileEvent {
    $event = new CaseFileEvent;
    $event->id = $order ?? 0;
    $event->eventCode = $code;
    $event->eventDate = $date !== null ? new DateTimeImmutable($date) : null;
    $event->eventOrder = $order;
    $event->detailJson = $detail;
    $event->refCourtKod = $foreign ? 'MSPHAAB' : null;
    $event->refRegistryNorm = $foreign ? 'CO' : null;
    // A row read from the repository always states this, NULL included - these
    // fixtures stand in for stored rows, not for patches.
    $event->parentEventOrder = $parentOrder;
    return $event;
}


test('overview patch reads the case-level scalars', function () {
    $patch = CaseSummaryExtraction::overviewPatch([
        'stav' => 'Nevyřízená věc',
        'stavDatum' => '13.08.2026',
        'napad' => 'Elektronický platební rozkaz',
        'udalosti' => [],
    ]);
    Assert::same('Nevyřízená věc', $patch->status);
    Assert::same('2026-08-13', $patch->statusDate?->format('Y-m-d'));
    Assert::same('Elektronický platební rozkaz', $patch->intakeKind);
});


test('unstated overview values become an explicit null', function () {
    $patch = CaseSummaryExtraction::overviewPatch(['stav' => ' ', 'stavDatum' => '-', 'napad' => null]);
    Assert::null($patch->status);
    Assert::null($patch->statusDate);
    Assert::null($patch->intakeKind);
    // Explicit null, not an untouched property: a case that stops stating a
    // value has to clear the column.
    Assert::true(isExplicit($patch, 'status'));
    Assert::true(isExplicit($patch, 'intakeKind'));
});


test('an unparseable status date is no date', function () {
    $patch = CaseSummaryExtraction::overviewPatch(['stavDatum' => '2026-08-13']);
    Assert::null($patch->statusDate);
});


test('subject patch reads PREDM_RIZ of the detail', function () {
    $patch = CaseSummaryExtraction::subjectPatch(eventDetail([
        ['typ' => 'PREDM_RIZ', 'hodnota' => 'o zaplacení 2 370 Kč s příslušenstvím'],
        ['typ' => 'PRED_VEC', 'hodnota' => '0 EPR 55796 / 2025'],
    ]));
    Assert::same('o zaplacení 2 370 Kč s příslušenstvím', $patch->subject);
});


test('a detail without PREDM_RIZ clears the subject', function () {
    Assert::null(CaseSummaryExtraction::subjectPatch(eventDetail([]))->subject);
    Assert::null(CaseSummaryExtraction::subjectPatch([])->subject);
    Assert::true(isExplicit(CaseSummaryExtraction::subjectPatch([]), 'subject'));
});


test('hearing patch mirrors the JED_* parsing', function () {
    $patch = CaseSummaryExtraction::hearingPatch([
        'typUdalosti' => 'NAR_JED',
        'atributy' => [
            ['typ' => 'JED_D_ZAC', 'hodnota' => '28.07.2026 08:30'],
            ['typ' => 'JED_SIN', 'hodnota' => 'č. dveří 307 ve III. podlaží'],
            ['typ' => 'JED_DRUH', 'hodnota' => 'Jednání'],
        ],
    ]);
    Assert::same('2026-07-28 08:30', $patch->hearingAt?->format('Y-m-d H:i'));
    Assert::same('č. dveří 307 ve III. podlaží', $patch->hearingRoom);
    Assert::same('Jednání', $patch->hearingType);
});


test('a detail with no hearing clears all three columns', function () {
    foreach ([CaseSummaryExtraction::hearingPatch(null), CaseSummaryExtraction::hearingPatch(eventDetail([]))] as $patch) {
        Assert::null($patch->hearingAt);
        Assert::null($patch->hearingRoom);
        Assert::null($patch->hearingType);
        Assert::true(isExplicit($patch, 'hearingAt'));
    }
});


test('the opening record states the case attributes', function () {
    $opening = storedEvent('ZAHAJ_RIZ', '2025-02-28', 1, '{}');
    $first = CaseSummaryExtraction::firstOwnDetailed([
        storedEvent('VYD_ROZH', '2024-01-01', 5, '{}'),
        $opening,
    ]);
    Assert::same($opening, $first);
});


test('without an opening record the earliest own detailed one is used', function () {
    $earliest = storedEvent('VYD_ROZH', '2025-06-25', 5, '{}');
    $first = CaseSummaryExtraction::firstOwnDetailed([
        storedEvent('ST_VEC_VYR', '2025-08-18', 11, '{}'),
        $earliest,
        storedEvent('NAR_JED', '2025-06-25', 12, '{}'), // same day, higher poradi
    ]);
    Assert::same($earliest, $first);
});


test('foreign and thin records state nothing', function () {
    Assert::null(CaseSummaryExtraction::firstOwnDetailed([
        storedEvent('ZAHAJ_RIZ', '2025-02-28', 1, null), // thin
        storedEvent('ODVOLANI', '2025-03-25', 1, '{}', foreign: true),
    ]));
});


test('an undated record sorts last', function () {
    $dated = storedEvent('VYD_ROZH', '2025-06-25', 9, '{}');
    Assert::same($dated, CaseSummaryExtraction::firstOwnDetailed([
        storedEvent('POZN', null, 2, '{}'),
        $dated,
    ]));
});


test('the record owing a detail is picked regardless of having one', function () {
    $events = [
        storedEvent('NAR_JED', '2026-03-01', 4, null),
        storedEvent('ZAHAJ_RIZ', '2026-01-05', 1, null),
    ];
    // firstOwnDetailed() needs a detail and finds none; firstOwn() answers
    // which record would state the attributes once fetched.
    Assert::null(CaseSummaryExtraction::firstOwnDetailed($events));
    Assert::same(1, CaseSummaryExtraction::firstOwn($events)->eventOrder);
});


test('a materialized hearing term is not the first own record', function () {
    // The nested term is earlier and has a detail, but upstream knows it only
    // through its aggregate - the sync picks from the top-level timeline.
    $events = [
        storedEvent('NAR_JED', '2026-01-02', 7, '{"atributy":[]}', parentOrder: 5),
        storedEvent('VYD_ROZH', '2026-02-01', 9, '{"atributy":[]}'),
    ];
    Assert::same(9, CaseSummaryExtraction::firstOwn($events)->eventOrder);
    Assert::same(9, CaseSummaryExtraction::firstOwnDetailed($events)->eventOrder);
});
