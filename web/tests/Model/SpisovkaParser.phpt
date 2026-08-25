<?php declare(strict_types=1);

use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$parser = new SpisovkaParser;


test('classic form with spaces around the slash', function () use ($parser) {
    $p = $parser->parse('24 NC 3601 / 2024');
    Assert::null($p->courtPrefix);
    Assert::same(24, $p->senate);
    Assert::same('NC', $p->registry);
    Assert::same(3601, $p->number);
    Assert::same(2024, $p->year);
    Assert::null($p->attachedNumber);
    Assert::same('24 NC 3601 / 2024', $p->format());
});


test('ISIR form with court prefix and no spaces', function () use ($parser) {
    $p = $parser->parse('KSPH 60INS19742/2024');
    Assert::same('KSPH', $p->courtPrefix);
    Assert::same(60, $p->senate);
    Assert::same('INS', $p->registry);
    Assert::same(19742, $p->number);
    Assert::same(2024, $p->year);
});


test('fully glued ISIR form', function () use ($parser) {
    $p = $parser->parse('ksph60ins19742/2024');
    Assert::same('KSPH', $p->courtPrefix);
    Assert::same(60, $p->senate);
    Assert::same('ins', $p->registry);
    Assert::same('INS', $p->registryNorm());
});


test('multi-word registry P a Nc', function () use ($parser) {
    $p = $parser->parse('0 P a Nc 205/2024');
    Assert::null($p->courtPrefix);
    Assert::same(0, $p->senate);
    Assert::same('P a Nc', $p->registry);
    Assert::same('P A NC', $p->registryNorm());
    Assert::same(205, $p->number);
});


test('leading sp. zn. label is stripped', function () use ($parser) {
    $p = $parser->parse('sp. zn. 12 C 34/2026');
    Assert::same('12 C 34 / 2026', $p->format());
});


test('c. j. with trailing page number', function () use ($parser) {
    $p = $parser->parse('č. j. 12 C 34/2026-15');
    Assert::same('12 C 34 / 2026', $p->format());
    Assert::same(15, $p->attachedNumber);
    Assert::null($p->ignoredText);
});


test('c. j. with a dangling dash is tolerated', function () use ($parser) {
    $p = $parser->parse('č. j. 32 T 51/2026-');
    Assert::same('32 T 51 / 2026', $p->format());
    Assert::null($p->attachedNumber);
    Assert::same('-', $p->ignoredText);
});


test('dash lookalikes are normalized', function () use ($parser) {
    // en dash, em dash and minus sign in place of the č. j. hyphen
    foreach (['–', '—', '−'] as $dash) {
        $p = $parser->parse("32 T 51/2026{$dash}15");
        Assert::same('32 T 51 / 2026', $p->format());
        Assert::same(15, $p->attachedNumber);
    }
});


test('slash lookalikes are normalized', function () use ($parser) {
    $p = $parser->parse('12 C 34⁄2026'); // fraction slash
    Assert::same('12 C 34 / 2026', $p->format());
});


test('surrounding junk is tolerated', function () use ($parser) {
    $p = $parser->parse('  („12 C 34/2026“)  ');
    Assert::same('12 C 34 / 2026', $p->format());
});


test('missing slash is tolerated when the year has 4 digits', function () use ($parser) {
    $p = $parser->parse('24 NC 3601 2024');
    Assert::same(2024, $p->year);
    Assert::same(3601, $p->number);
});


test('missing year is reported', function () use ($parser) {
    Assert::exception(
        fn() => $parser->parse('12 C 34'),
        SpisovkaParseException::class,
        'Není uveden rok%a%',
    );
});


test('two-digit year at or below the current one is this century', function () use ($parser) {
    Assert::same(2024, $parser->parse('12 C 34/24')->year);
    Assert::same(2000, $parser->parse('12 C 34/00')->year);
    // The pivot follows the current year, so this stays true next year too.
    $currentShort = (int) date('y');
    Assert::same(2000 + $currentShort, $parser->parse("12 C 34/$currentShort")->year);
});


test('two-digit year above the current one is the 20th century', function () use ($parser) {
    // Pre-2000 cases are still live (guardianship files) and the court writes
    // them with the two-digit token, e.g. "0 P 480/61".
    Assert::same(1998, $parser->parse('12 C 34/98')->year);
    Assert::same(1961, $parser->parse('0 P 480/61')->year);
});


test('four-digit year in the future is rejected', function () use ($parser) {
    // Guards the upstream quirk: infosoud matches a pre-2000 case on the last
    // two digits, so 2098 would silently answer with the 1998 case.
    Assert::exception(
        fn() => $parser->parse('12 C 34/2098'),
        SpisovkaParseException::class,
        '%a%budoucnosti%a%',
    );
});


test('three-digit year is rejected with a hint', function () use ($parser) {
    Assert::exception(
        fn() => $parser->parse('12 C 34/123'),
        SpisovkaParseException::class,
        '%a%zapište ročník celý%a%',
    );
});


test('dash, dot and space work as the year separator', function () use ($parser) {
    foreach (['12 C 34-2026', '12 C 34.2026', '12 C 34 2026', '12 C 34-26', '12 C 34.26', '12 C 34 26'] as $input) {
        $p = $parser->parse($input);
        Assert::same(2026, $p->year, $input);
        Assert::same(34, $p->number, $input);
    }
});


test('dash year separator keeps the c. j. page number working', function () use ($parser) {
    $p = $parser->parse('12 C 34-2026-15');
    Assert::same(2026, $p->year);
    Assert::same(15, $p->attachedNumber);
});


test('missing senate number is reported', function () use ($parser) {
    Assert::exception(
        fn() => $parser->parse('C 34/2026'),
        SpisovkaParseException::class,
        'Chybí číslo senátu%a%',
    );
});


test('nonsense input is rejected', function () use ($parser) {
    Assert::exception(
        fn() => $parser->parse('blbost'),
        SpisovkaParseException::class,
        'Chybí číslo senátu%a%',
    );
});


test('empty input is rejected', function () use ($parser) {
    Assert::exception(
        fn() => $parser->parse('   '),
        SpisovkaParseException::class,
        'Zadejte spisovou značku.',
    );
});


test('trailing garbage is dropped and reported via ignoredText', function () use ($parser) {
    $p = $parser->parse('12 C 34/2026 xyz abc');
    Assert::same('12 C 34 / 2026', $p->format());
    Assert::same('xyz abc', $p->ignoredText);
});
