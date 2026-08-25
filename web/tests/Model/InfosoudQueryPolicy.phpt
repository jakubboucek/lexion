<?php declare(strict_types=1);

use App\Model\Infosoud\InfosoudQueryPolicy;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('juvenile-justice registries are never queryable', function () {
    Assert::false(InfosoudQueryPolicy::isQueryableRegistry('TM'));
    Assert::false(InfosoudQueryPolicy::isQueryableRegistry('TMO'));
    Assert::false(InfosoudQueryPolicy::isQueryableRegistry('NTM'));
});


test('ordinary registries pass, including Nt which the infosoud help omits', function () {
    Assert::true(InfosoudQueryPolicy::isQueryableRegistry('C'));
    Assert::true(InfosoudQueryPolicy::isQueryableRegistry('T'));
    Assert::true(InfosoudQueryPolicy::isQueryableRegistry('P A NC'));
    // empirically served by the API despite missing from the documented list
    Assert::true(InfosoudQueryPolicy::isQueryableRegistry('NT'));
});


test('the check expects the norm form - display forms are not its input', function () {
    // "Tm" (display) is not a norm form; guard against accidental misuse
    Assert::true(InfosoudQueryPolicy::isQueryableRegistry('Tm'));
});
