<?php declare(strict_types=1);

use App\Model\Infosoud\InfosoudHearing;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** @param list<array{typ: string, hodnota: ?string}> $attributes */
function detail(array $attributes): array
{
    return ['typUdalosti' => 'NAR_JED', 'atributy' => $attributes];
}


test('full JED_* set parses into all fields', function () {
    $hearing = InfosoudHearing::fromEventDetail(detail([
        ['typ' => 'JED_DRUH', 'hodnota' => 'Jednání'],
        ['typ' => 'JED_SIN', 'hodnota' => 'č. dveří 307 ve III. podlaží'],
        ['typ' => 'JED_D_ZAC', 'hodnota' => '28.07.2026 08:30'],
        ['typ' => 'JED_VYSLED', 'hodnota' => 'Jednání bylo odročeno'],
        ['typ' => 'JED_ZRUS', 'hodnota' => 'Ne'],
    ]));
    Assert::notNull($hearing);
    Assert::same('2026-07-28 08:30', $hearing->startsAt?->format('Y-m-d H:i'));
    Assert::same('č. dveří 307 ve III. podlaží', $hearing->room);
    Assert::same('Jednání', $hearing->type);
    Assert::same('Jednání bylo odročeno', $hearing->result);
    Assert::false($hearing->cancelled);
});


test('cancellation flag requires the literal Ano', function () {
    $cancelled = InfosoudHearing::fromEventDetail(detail([
        ['typ' => 'JED_D_ZAC', 'hodnota' => '01.08.2026 09:00'],
        ['typ' => 'JED_ZRUS', 'hodnota' => 'Ano'],
    ]));
    Assert::true($cancelled?->cancelled);
});


test('"-" and blank values mean not stated and become null', function () {
    // The room "-" must NOT survive as a literal string: hearing-bind compares
    // room labels and a literal "-" would be a false mismatch (see CH-2).
    $hearing = InfosoudHearing::fromEventDetail(detail([
        ['typ' => 'JED_D_ZAC', 'hodnota' => '28.07.2026 08:30'],
        ['typ' => 'JED_SIN', 'hodnota' => '-'],
        ['typ' => 'JED_DRUH', 'hodnota' => ''],
        ['typ' => 'JED_VYSLED', 'hodnota' => null],
    ]));
    Assert::notNull($hearing);
    Assert::null($hearing->room);
    Assert::null($hearing->type);
    Assert::null($hearing->result);
});


test('unparseable or missing start time yields null startsAt', function () {
    $hearing = InfosoudHearing::fromEventDetail(detail([
        ['typ' => 'JED_SIN', 'hodnota' => 'síň 1'],
    ]));
    Assert::notNull($hearing);
    Assert::null($hearing->startsAt);

    $garbled = InfosoudHearing::fromEventDetail(detail([
        ['typ' => 'JED_D_ZAC', 'hodnota' => 'zítra ráno'],
        ['typ' => 'JED_SIN', 'hodnota' => 'síň 1'],
    ]));
    Assert::null($garbled?->startsAt);
});


test('a detail without any JED_* attribute is not a hearing', function () {
    Assert::null(InfosoudHearing::fromEventDetail(detail([
        ['typ' => 'PREDM_RIZ', 'hodnota' => 'zaplacení 4 519 Kč'],
    ])));
    Assert::null(InfosoudHearing::fromEventDetail([]));
});
