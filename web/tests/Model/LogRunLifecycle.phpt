<?php declare(strict_types=1);

use App\Bootstrap;
use App\Model\Log\LogContextProvider;
use App\Model\Log\LogEventKind;
use App\Model\Log\LogRepository;
use App\Model\Log\LogRunChannel;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
use Nette\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

// The run lifecycle against the real `log` table; skip without a database.
try {
    $container = (new Bootstrap)->bootConsoleApplication();
    $db = $container->getByType(Explorer::class);
    $db->table('log')->limit(1)->fetchAll();
} catch (\Throwable $e) {
    Tester\Environment::skip('Database not available: ' . $e->getMessage());
}

enum TestLogKind: string implements LogEventKind
{
    case Run = 'lifecycle';

    public function resource(): string
    {
        return 'test';
    }
}

$logDir = sys_get_temp_dir() . '/lexion-log-' . bin2hex(random_bytes(4));
mkdir($logDir);
$service = new LogService(
    $container->getByType(LogRepository::class),
    $container->getByType(LogContextProvider::class),
    $logDir,
);
$createdIds = [];


test('an instant record lands complete and final', function () use ($service, $db, &$createdIds) {
    $entry = $service->log(TestLogKind::Run, LogStatus::Failed, target: 't', result: 'r', message: 'm', data: ['k' => 1]);
    $createdIds[] = $entry->id;

    $row = $db->table('log')->get($entry->id);
    assert($row !== null);
    Assert::same('test', (string) $row->resource);
    Assert::same('lifecycle', (string) $row->action);
    Assert::same('failed', (string) $row->status);
    Assert::same('{"k":1}', (string) $row->data);
    Assert::null($row->files);
    Assert::null($row->finished_at);
    Assert::contains('"origin":"cli"', (string) $row->context);
});


test('an instant record refuses the pending status', function () use ($service) {
    Assert::exception(
        fn() => $service->log(TestLogKind::Run, LogStatus::Pending),
        InvalidArgumentException::class,
    );
});


test('a run: pending at start, one finishing update, empty files deleted and NULLed', function () use ($service, $db, $logDir, &$createdIds) {
    $session = $service->createRunSession(TestLogKind::Run, target: 'part-2.jsonl.gz', data: ['part' => 2]);
    $out = $session->textFile(LogRunChannel::Out);
    $err = $session->textFile(LogRunChannel::Err); // stays empty
    $problems = $session->jsonlFile('problems');

    $run = $session->start();
    $createdIds[] = $run->id;

    $row = $db->table('log')->get($run->id);
    assert($row !== null);
    Assert::same('pending', (string) $row->status);
    Assert::null($row->finished_at);
    $files = json_decode((string) $row->files, associative: true);
    Assert::same(['out', 'err', 'problems'], array_keys($files));
    Assert::true(file_exists($logDir . '/' . $files['err']));

    $out->writeLine('progress');
    $problems->write(['subject' => 'S']);
    $run->finish(LogStatus::Ok, message: 'done', resultData: ['created' => 3]);

    $row = $db->table('log')->get($run->id);
    assert($row !== null);
    Assert::same('ok', (string) $row->status);
    Assert::same('{"created":3}', (string) $row->result_data);
    Assert::notNull($row->finished_at);
    $files = json_decode((string) $row->files, associative: true);
    Assert::null($files['err']);
    Assert::match('run-%a%-test-lifecycle-%a%-out.log', $files['out']);
    Assert::true(file_exists($logDir . '/' . $files['out']));
    Assert::false((bool) glob($logDir . '/*-err.log')); // empty file deleted
});


test('finish is idempotent and a session starts only once', function () use ($service, $db, &$createdIds) {
    $session = $service->createRunSession(TestLogKind::Run);
    $out = $session->textFile(LogRunChannel::Out);
    $run = $session->start();
    $createdIds[] = $run->id;

    $out->writeLine('once');
    $run->finish(LogStatus::Failed, result: 'aborted');
    $run->finish(LogStatus::Ok); // ignored

    $row = $db->table('log')->get($run->id);
    assert($row !== null);
    Assert::same('failed', (string) $row->status);
    Assert::same('aborted', (string) $row->result);

    Assert::exception(fn() => $session->start(), LogicException::class);
    Assert::exception(fn() => $session->textFile('late'), LogicException::class);
});


test('a run refuses to finish as pending and validates meanings', function () use ($service) {
    $session = $service->createRunSession(TestLogKind::Run);
    $session->textFile('twice');
    Assert::exception(fn() => $session->textFile('twice'), InvalidArgumentException::class, '%a%already registered%a%');
    Assert::exception(fn() => $session->jsonlFile('Bad Name'), InvalidArgumentException::class, 'Invalid file meaning%a%');
});


// The rows are test noise, not evidence - clean them up.
$db->table('log')->where('id', $createdIds)->delete();
