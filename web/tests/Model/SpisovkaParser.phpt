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
    Assert::same('24 NC 3601/2024', $p->format());
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
    Assert::same('12 C 34/2026', $p->format());
});


test('c. j. with trailing page number', function () use ($parser) {
    $p = $parser->parse('č. j. 12 C 34/2026-15');
    Assert::same('12 C 34/2026', $p->format());
    Assert::same(15, $p->attachedNumber);
});


test('surrounding junk is tolerated', function () use ($parser) {
    $p = $parser->parse('  („12 C 34/2026")  ');
    Assert::same('12 C 34/2026', $p->format());
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


test('two-digit year is rejected with a hint', function () use ($parser) {
    Assert::exception(
        fn() => $parser->parse('12 C 34/24'),
        SpisovkaParseException::class,
        '%a%4 číslice%a%',
    );
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


test('trailing garbage is reported', function () use ($parser) {
    Assert::exception(
        fn() => $parser->parse('12 C 34/2026 xyz abc'),
        SpisovkaParseException::class,
        'Za spisovou značkou přebývá text%a%',
    );
});
