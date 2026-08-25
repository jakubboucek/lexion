<?php declare(strict_types=1);

use App\Model\Spisovka\Spisovka;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('display, norm and slug forms of a simple registry', function () {
    $s = new Spisovka(24, 'NC', 3601, 2024);
    Assert::same('24 NC 3601 / 2024', $s->format());
    Assert::same('NC', $s->registryNorm());
    Assert::same('24-nc-3601-2024', $s->toSlug());
});


test('multi-word registry keeps display, compacts in slug', function () {
    $s = new Spisovka(0, 'P a Nc', 141, 2024);
    Assert::same('0 P a Nc 141 / 2024', $s->format());
    Assert::same('P A NC', $s->registryNorm());
    Assert::same('0-panc-141-2024', $s->toSlug());
});


test('diacritics are dropped in the slug but kept in display', function () {
    $s = new Spisovka(1, 'NSČR', 5, 2023);
    Assert::same('1 NSČR 5 / 2023', $s->format());
    Assert::same('NSČR', $s->registryNorm());
    Assert::same('1-nscr-5-2023', $s->toSlug());
});


test('norm is invariant to registry casing', function () {
    foreach (['P a Nc', 'p a nc', 'P A NC'] as $registry) {
        Assert::same('P A NC', (new Spisovka(0, $registry, 141, 2024))->registryNorm());
    }
});


test('slug is invariant to registry casing and spacing', function () {
    foreach (['P a Nc', 'p a nc', 'P A NC', 'PANC', 'panc'] as $registry) {
        Assert::same('0-panc-141-2024', (new Spisovka(0, $registry, 141, 2024))->toSlug());
    }
});


test('slugifyRegistry strips spaces, diacritics and lowercases', function () {
    Assert::same('panc', Spisovka::slugifyRegistry('P a Nc'));
    Assert::same('nscr', Spisovka::slugifyRegistry('NSČR'));
    Assert::same('ins', Spisovka::slugifyRegistry('INS'));
});


test('pre-2000 year is displayed as the court writes it, but stays full in the slug', function () {
    // Internally the year is always full; the court writes "0 P 480/61".
    $s = new Spisovka(0, 'P', 480, 1961);
    Assert::same('0 P 480 / 61', $s->format());
    // Our URL is strict about the full year (SpisovkaSlugParser rejects two digits).
    Assert::same('0-p-480-1961', $s->toSlug());
});
