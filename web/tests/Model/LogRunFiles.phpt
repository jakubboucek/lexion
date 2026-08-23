<?php declare(strict_types=1);

use App\Model\Log\LogRunJsonlFile;
use App\Model\Log\LogRunTextFile;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

$dir = sys_get_temp_dir() . '/lexion-log-' . bin2hex(random_bytes(4));
mkdir($dir);


test('a text file writes timestamped, greppable lines', function () use ($dir) {
    $file = new LogRunTextFile('a-out.log', $dir . '/a-out.log');
    $file->open();
    $file->writeLine('first line');
    $file->writeLine('second line');
    Assert::true($file->close());
    Assert::match(
        "[%d%-%d%-%d% %d%:%d%:%d%.%d%] first line\n[%d%-%d%-%d% %d%:%d%:%d%.%d%] second line\n",
        file_get_contents($dir . '/a-out.log'),
    );
});


test('a jsonl file writes one object per line and stamps ts itself', function () use ($dir) {
    $file = new LogRunJsonlFile('b-problems.jsonl', $dir . '/b-problems.jsonl');
    $file->open();
    $file->write(['subject' => 'X', 'ts' => 'caller value must lose']);
    Assert::true($file->close());

    $lines = file($dir . '/b-problems.jsonl', FILE_IGNORE_NEW_LINES);
    Assert::count(1, $lines);
    $record = json_decode($lines[0], associative: true);
    Assert::same('X', $record['subject']);
    Assert::match('%d%-%d%-%d% %d%:%d%:%d%.%d%', $record['ts']);
});


test('a jsonl record may be JsonSerializable as long as it yields an array', function () use ($dir) {
    $file = new LogRunJsonlFile('c.jsonl', $dir . '/c.jsonl');
    $file->open();
    $file->write(new class implements JsonSerializable {
        public function jsonSerialize(): array
        {
            return ['kind' => 'object'];
        }
    });
    $file->close();
    Assert::contains('"kind":"object"', (string) file_get_contents($dir . '/c.jsonl'));

    $file = new LogRunJsonlFile('d.jsonl', $dir . '/d.jsonl');
    $file->open();
    Assert::exception(
        fn() => $file->write(new class implements JsonSerializable {
            public function jsonSerialize(): string
            {
                return 'a scalar';
            }
        }),
        InvalidArgumentException::class,
    );
});


test('a writer refuses input before open and after close', function () use ($dir) {
    $file = new LogRunTextFile('e.log', $dir . '/e.log');
    Assert::exception(fn() => $file->writeLine('too early'), LogicException::class, '%a%not been started%a%');

    $file->open();
    $file->writeLine('alive');
    $file->close();
    Assert::exception(fn() => $file->writeLine('too late'), LogicException::class, '%a%already finished%a%');
});


test('close reports an untouched file so the run can delete it', function () use ($dir) {
    $file = new LogRunTextFile('f.log', $dir . '/f.log');
    $file->open();
    Assert::false($file->close());
    // The file itself exists (opened in append mode); deleting is LogRun's job.
    Assert::true(file_exists($dir . '/f.log'));
});
