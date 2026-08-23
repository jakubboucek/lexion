<?php declare(strict_types=1);

use App\Model\CaseFile\CaseFileEvent;
use App\Model\CaseFile\CaseFileProjectionPlan;
use App\Model\CaseFile\CaseFileRelation;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * A stored event row. Only the fields the diff reads are set; $id and
 * $detailJson exist only on stored rows (incoming ones are unsaved).
 */
function storedEvent(
    int $id,
    string $code,
    ?int $order,
    ?string $date,
    ?string $detailJson = null,
    bool $cancelled = false,
    ?string $refCourtKod = null,
): CaseFileEvent {
    $event = incomingEvent($code, $order, $date, $cancelled, $refCourtKod);
    $event->id = $id;
    $event->detailJson = $detailJson;
    return $event;
}


/** An event row as projected from a fresh upstream payload. */
function incomingEvent(
    string $code,
    ?int $order,
    ?string $date,
    bool $cancelled = false,
    ?string $refCourtKod = null,
): CaseFileEvent {
    $event = new CaseFileEvent;
    $event->eventCode = $code;
    $event->eventOrder = $order;
    $event->upstreamId = null;
    $event->eventDate = $date !== null ? new DateTimeImmutable($date) : null;
    $event->cancelled = $cancelled;
    $event->refCourtKod = $refCourtKod;
    $event->refRegistryNorm = $refCourtKod !== null ? 'TO' : null;
    $event->refSenate = $refCourtKod !== null ? 5 : null;
    $event->refBcNumber = $refCourtKod !== null ? 320 : null;
    $event->refYear = $refCourtKod !== null ? 2025 : null;
    return $event;
}


function relation(string $type, ?string $dstCourtKod, int $bcNumber, ?int $id = null): CaseFileRelation
{
    $relation = new CaseFileRelation;
    if ($id !== null) {
        $relation->id = $id;
    }
    $relation->dstCourtKod = $dstCourtKod;
    $relation->dstRegistryNorm = 'C';
    $relation->dstSenate = 12;
    $relation->dstBcNumber = $bcNumber;
    $relation->dstYear = 2024;
    $relation->relationType = $type;
    return $relation;
}


test('an unchanged timeline plans nothing and is not destructive', function () {
    $plan = CaseFileProjectionPlan::diff(
        [storedEvent(1, 'ZAHAJ_RIZ', 1, '2024-01-10'), storedEvent(2, 'NAR_JED', 4, '2024-03-01')],
        [incomingEvent('ZAHAJ_RIZ', 1, '2024-01-10'), incomingEvent('NAR_JED', 4, '2024-03-01')],
        [relation('ODVOLANI', 'KSSTCAB', 320, id: 7)],
        [relation('ODVOLANI', 'KSSTCAB', 320)],
    );
    Assert::same([], $plan->eventInserts);
    Assert::same([], $plan->eventDeletes);
    Assert::same([], $plan->relationInserts);
    Assert::same([], $plan->relationDeletes);
    Assert::count(2, $plan->eventUpdates); // paired rows produce (empty) patches
    Assert::false($plan->isDestructive());
    Assert::same([], $plan->lossContext());
});


test('a new event is an insert only', function () {
    $plan = CaseFileProjectionPlan::diff(
        [storedEvent(1, 'ZAHAJ_RIZ', 1, '2024-01-10')],
        [incomingEvent('ZAHAJ_RIZ', 1, '2024-01-10'), incomingEvent('VYD_ROZH', 9, '2024-06-01')],
        [],
        [],
    );
    Assert::count(1, $plan->eventInserts);
    Assert::same('VYD_ROZH', $plan->eventInserts[0]->eventCode);
    Assert::same([], $plan->eventDeletes);
    Assert::false($plan->isDestructive());
});


test('a renumbered poradi is a delete + insert, never an update', function () {
    // Same code, same date - only the upstream poradi shifted. The pairing
    // key contains it, so the old row cannot pair: this is exactly the
    // destruction the journal exists to record.
    $plan = CaseFileProjectionPlan::diff(
        [storedEvent(10, 'NAR_JED', 5, '2024-02-13', detailJson: '{"x":1}')],
        [incomingEvent('NAR_JED', 7, '2024-02-13')],
        [],
        [],
    );
    Assert::count(1, $plan->eventInserts);
    Assert::count(1, $plan->eventDeletes);
    Assert::same(10, $plan->eventDeletes[0]->id);
    Assert::true($plan->isDestructive());

    $context = $plan->lossContext();
    Assert::count(1, $context['droppedEvents']);
    Assert::same(10, $context['droppedEvents'][0]['id']);
    Assert::true($context['droppedEvents'][0]['hadDetail']);
});


test('a moved date on a paired row wipes its fetched detail (destructive)', function () {
    $plan = CaseFileProjectionPlan::diff(
        [storedEvent(10, 'VYD_ROZH', 4, '2024-02-13', detailJson: '{"x":1}')],
        [incomingEvent('VYD_ROZH', 4, '2024-02-20')],
        [],
        [],
    );
    Assert::count(1, $plan->eventUpdates);
    $update = $plan->eventUpdates[0];
    Assert::true($update->dropsDetail);
    Assert::null($update->changes->detailJson);
    Assert::null($update->changes->detailFetchedAt);
    Assert::same('2024-02-20', $update->changes->eventDate?->format('Y-m-d'));
    Assert::true($plan->isDestructive());
    Assert::same('2024-02-13', $plan->lossContext()['droppedDetails'][0]['dateBefore']);
});


test('a moved date without a stored detail is a plain update, not destructive', function () {
    $plan = CaseFileProjectionPlan::diff(
        [storedEvent(10, 'VYD_ROZH', 4, '2024-02-13')],
        [incomingEvent('VYD_ROZH', 4, '2024-02-20')],
        [],
        [],
    );
    Assert::count(1, $plan->eventUpdates);
    Assert::false($plan->eventUpdates[0]->dropsDetail);
    Assert::false($plan->isDestructive());
});


test('a cancelled flip is a plain update, not destructive', function () {
    $plan = CaseFileProjectionPlan::diff(
        [storedEvent(10, 'NAR_JED', 5, '2024-02-13', detailJson: '{"x":1}')],
        [incomingEvent('NAR_JED', 5, '2024-02-13', cancelled: true)],
        [],
        [],
    );
    $update = $plan->eventUpdates[0];
    Assert::true($update->changes->cancelled);
    Assert::false(isset($update->changes->eventDate)); // untouched patch fields stay uninitialized
    Assert::false($plan->isDestructive());
});


test('foreign events with the same code and poradi pair by their owner case', function () {
    // NC 3601 lists 8x ODVOLANI, all poradi 1 - only the owner case differs.
    $plan = CaseFileProjectionPlan::diff(
        [storedEvent(1, 'ODVOLANI', 1, '2024-01-10', refCourtKod: 'KSSTCAB')],
        [
            incomingEvent('ODVOLANI', 1, '2024-01-10', refCourtKod: 'KSSTCAB'),
            incomingEvent('ODVOLANI', 1, '2024-01-11', refCourtKod: 'KSJICCB'),
        ],
        [],
        [],
    );
    Assert::count(1, $plan->eventInserts);
    Assert::same('KSJICCB', $plan->eventInserts[0]->refCourtKod);
    Assert::same([], $plan->eventDeletes);
});


test('relations: rows present on both sides survive, missing ones are deletes', function () {
    $plan = CaseFileProjectionPlan::diff(
        [],
        [],
        [relation('ODVOLANI', 'KSSTCAB', 320, id: 7), relation('PRED_VEC', null, 100, id: 8)],
        [relation('ODVOLANI', 'KSSTCAB', 320), relation('NAVAZNA_VEC', 'MSPHAAB', 55)],
    );
    Assert::count(1, $plan->relationInserts);
    Assert::same('NAVAZNA_VEC', $plan->relationInserts[0]->relationType);
    Assert::count(1, $plan->relationDeletes);
    Assert::same(8, $plan->relationDeletes[0]->id);
    Assert::true($plan->isDestructive());
    Assert::same('PRED_VEC', $plan->lossContext()['droppedRelations'][0]['relationType']);
});
