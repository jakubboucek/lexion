<?php declare(strict_types=1);

use App\Model\Codelist\CourtLevel;
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


test('Nc is refused at regional and high courts, served everywhere else', function () {
    // Empirically: RIZENI_VALIDATION_0005 ("chybná agenda") at KS/VS, while
    // the same registry answers normally at OS and NS (verified 2026-08-27).
    Assert::false(InfosoudQueryPolicy::isQueryableAt('NC', CourtLevel::Regional));
    Assert::false(InfosoudQueryPolicy::isQueryableAt('NC', CourtLevel::High));
    Assert::true(InfosoudQueryPolicy::isQueryableAt('NC', CourtLevel::District));
    Assert::true(InfosoudQueryPolicy::isQueryableAt('NC', CourtLevel::Supreme));
    // The registry itself is answerable somewhere, so the file number alone
    // must not be rejected - only the pairing with a court can be.
    Assert::true(InfosoudQueryPolicy::isQueryableRegistry('NC'));
});


test('a court-level check also applies the blanket refusals', function () {
    Assert::false(InfosoudQueryPolicy::isQueryableAt('TM', CourtLevel::District));
    Assert::false(InfosoudQueryPolicy::isQueryableAt('TMO', CourtLevel::Regional));
    Assert::true(InfosoudQueryPolicy::isQueryableAt('C', CourtLevel::Regional));
});


test('neighbouring registries keep working at regional courts', function () {
    // Nco/Ncd/Ncp are separate registries, not spellings of Nc.
    Assert::true(InfosoudQueryPolicy::isQueryableAt('NCO', CourtLevel::Regional));
    Assert::true(InfosoudQueryPolicy::isQueryableAt('NCD', CourtLevel::Regional));
    Assert::true(InfosoudQueryPolicy::isQueryableAt('NCP', CourtLevel::Regional));
});


test('the refusal reason names the actual reason, or is absent', function () {
    Assert::null(InfosoudQueryPolicy::refusalReason('C', 'C', CourtLevel::Regional));
    // Without a court only the blanket refusals can be stated.
    Assert::null(InfosoudQueryPolicy::refusalReason('NC', 'Nc', null));

    Assert::contains('mládeže', (string) InfosoudQueryPolicy::refusalReason('TM', 'Tm', null));
    $agenda = (string) InfosoudQueryPolicy::refusalReason('NC', 'Nc', CourtLevel::Regional);
    Assert::contains('Nc', $agenda);
    Assert::notContains('mládeže', $agenda);
});
