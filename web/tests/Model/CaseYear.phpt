<?php declare(strict_types=1);

use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\SpisovkaParseException;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('user input: two-digit shorthand pivots on the current year', function () {
    Assert::same(2024, CaseYear::fromUserInput('24', 2026));
    Assert::same(2026, CaseYear::fromUserInput('26', 2026));
    Assert::same(1927, CaseYear::fromUserInput('27', 2026));
    Assert::same(1998, CaseYear::fromUserInput('98', 2026));
    // The pivot moves with the year: "27" is this century once 2027 arrives.
    Assert::same(2027, CaseYear::fromUserInput('27', 2027));
});


test('user input: four-digit year is taken as is', function () {
    Assert::same(1961, CaseYear::fromUserInput('1961', 2026));
    Assert::same(2024, CaseYear::fromUserInput('2024', 2026));
});


test('user input: future and nonsense years are rejected', function () {
    Assert::exception(
        fn() => CaseYear::fromUserInput('2098', 2026),
        SpisovkaParseException::class,
        '%a%budoucnosti%a%',
    );
    Assert::exception(
        fn() => CaseYear::fromUserInput('1899', 2026),
        SpisovkaParseException::class,
        '%a%20. století%a%',
    );
    Assert::exception(
        fn() => CaseYear::fromUserInput('123', 2026),
        SpisovkaParseException::class,
        '%a%zapište ročník celý%a%',
    );
});


test('upstream: two digits always mean the 20th century, no pivot', function () {
    // Infosoud echoes modern cases in full ("rocnik": 2023), so a two-digit
    // value in its data can only be a pre-2000 case - applying the user-input
    // pivot here would turn a 1905 case into 2005.
    Assert::same(1961, CaseYear::fromUpstream(61));
    Assert::same(1905, CaseYear::fromUpstream(5));
    Assert::same(1999, CaseYear::fromUpstream(99));
    Assert::same(2023, CaseYear::fromUpstream(2023));
});


test('outbound: API gets the two-digit token, display the court form', function () {
    Assert::same(61, CaseYear::forApi(1961));
    Assert::same(2024, CaseYear::forApi(2024));

    Assert::same('61', CaseYear::forDisplay(1961));
    Assert::same('2024', CaseYear::forDisplay(2024));
    // Zero padded: a 1905 case is "05", never "5".
    Assert::same('05', CaseYear::forDisplay(1905));
});


test('round trip through the boundaries keeps the internal year', function () {
    foreach ([1961, 1998, 2024] as $year) {
        Assert::same($year, CaseYear::fromUpstream(CaseYear::forApi($year)), (string) $year);
    }
});
