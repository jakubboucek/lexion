<?php declare(strict_types=1);

use App\Bootstrap;
use App\Model\Spisovka\Spisovka;
use Nette\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

// This test verifies the registry codelist against the deterministic forward
// transforms done in PHP (so the DB seed never drifts from the code). It needs
// the database; skip when it is not reachable.
try {
    $container = (new Bootstrap)->bootConsoleApplication();
    $explorer = $container->getByType(Explorer::class);
    $rows = $explorer->table('registry')->fetchAll();
} catch (\Throwable $e) {
    Tester\Environment::skip('Database not available: ' . $e->getMessage());
}


test('code_norm equals uppercase(code) for every row', function () use ($rows) {
    foreach ($rows as $row) {
        Assert::same(mb_strtoupper((string) $row->code), (string) $row->code_norm, "code_norm of {$row->code}");
    }
});


test('slug equals slugifyRegistry(code) for every row', function () use ($rows) {
    foreach ($rows as $row) {
        Assert::same(Spisovka::slugifyRegistry((string) $row->code), (string) $row->slug, "slug of {$row->code}");
    }
});


test('slug uniquely identifies a display code (no cross-code collision)', function () use ($rows) {
    $byslug = [];
    foreach ($rows as $row) {
        $byslug[(string) $row->slug][(string) $row->code] = true;
    }
    foreach ($byslug as $slug => $codes) {
        Assert::count(1, $codes, "slug '$slug' maps to a single display code");
    }
});
