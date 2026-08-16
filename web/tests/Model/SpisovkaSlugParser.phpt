<?php declare(strict_types=1);

use App\Bootstrap;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaSlugParser;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

// The slug parser is the entry point for untrusted URL input (the /spis route)
// and needs the registry codelist for the lossy slug -> display translation;
// skip when the database is not reachable.
try {
    $container = (new Bootstrap)->bootConsoleApplication();
    $parser = $container->getByType(SpisovkaSlugParser::class);
    $parser->parse('1-c-1-2023');
} catch (\Throwable $e) {
    Tester\Environment::skip('Database not available: ' . $e->getMessage());
}


test('canonical slug round-trips through the codelist', function () use ($parser) {
    $spisovka = $parser->parse('24-panc-141-2024');
    Assert::same(24, $spisovka->senate);
    Assert::same('P a Nc', $spisovka->registry);   // lossy slug -> display form
    Assert::same(141, $spisovka->number);
    Assert::same(2024, $spisovka->year);
    Assert::same('24-panc-141-2024', $spisovka->toSlug());
});


test('wrong-case slug parses (canonicalized later by redirect, not 404)', function () use ($parser) {
    Assert::same('C', $parser->parse('30-C-1-2023')->registry);
});


test('a registry missing from the codelist falls back to uppercase', function () use ($parser) {
    Assert::same('XYZQ', $parser->parse('1-xyzq-1-2023')->registry);
});


test('malformed slugs are rejected', function () use ($parser) {
    $reject = function (string $slug) use ($parser): void {
        Assert::exception(
            fn() => $parser->parse($slug),
            SpisovkaParseException::class,
        );
    };
    $reject('');
    $reject('30-c-1');                 // missing year
    $reject('30-c-1-2023-extra');      // too many segments
    $reject('30-c-1-23');              // two-digit year is refused by design
    $reject('x-c-1-2023');             // non-numeric senate
    $reject('30-c-x-2023');            // non-numeric number
    $reject('30-č-1-2023');            // registry segment must be ASCII alphanumeric
    $reject('30--1-2023');             // empty registry segment
});
