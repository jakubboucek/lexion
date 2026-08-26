<?php declare(strict_types=1);

use App\Model\Hearing\ScanResponseFile;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


// The name is the on-disk identity of every stored infoJednani response
// (~48k files migrated 2026-08-26). Any change to the derivation orphans
// them all, so the exact outputs are pinned here. Labels are real codelist
// entries.
test('name derivation is stable', function () {
    Assert::same('66-prizemi-0ee47fd4.json', ScanResponseFile::nameFor('66 přízemí'));
    // The 15-char cut falls inside the label, mid-word - Strings::substring
    // is multibyte-safe, so diacritics never get split into invalid UTF-8.
    Assert::same('c-dveri-307-ve-94e58b01.json', ScanResponseFile::nameFor('č. dveří 307 ve III. podlaží'));
});


test('crc32 of the full label disambiguates a shared 15-char prefix', function () {
    $a = ScanResponseFile::nameFor('Lidická třída 20, II. patro č. dv. 315');
    $b = ScanResponseFile::nameFor('Lidická třída 20, III. patro č. dv. 407');
    Assert::same('lidicka-trida-2-fd4c8085.json', $a);
    Assert::same('lidicka-trida-2-0b15be49.json', $b);
    Assert::notSame($a, $b);
});


test('labels differing only in case stay distinct on case-insensitive filesystems', function () {
    // Webalize lowercases the prefix, so the distinction must survive in the
    // crc32, which hashes the raw label.
    Assert::same('misto-same-18a82fea.json', ScanResponseFile::nameFor('Místo samé'));
    Assert::same('misto-same-0099f412.json', ScanResponseFile::nameFor('místo samé'));
});
