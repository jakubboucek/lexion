<?php declare(strict_types=1);

use App\Model\Hearing\RoomClassifier;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


// Labels taken from the real infoJednani codelist snapshot; the classification
// drives hearing_room.off_site, which will weaken the venue-court guess.
test('off-site places are recognized', function () {
    Assert::same(['prison', true], RoomClassifier::classify('jednací síň ve Věznici Valdice'));
    Assert::same(['hospital', true], RoomClassifier::classify('Psychiatrická nemocnice Bohnice, pavilon 17'));
    Assert::same(['onsite', true], RoomClassifier::classify('na místě samém'));
    Assert::same(['onsite', true], RoomClassifier::classify('MÍSTNÍ OHLEDÁNÍ'));
    Assert::same(['external', true], RoomClassifier::classify('výslech mimo budovu soudu'));
});


test('in-house special rooms stay on site', function () {
    // A detention room is inside the courthouse - only an actual prison
    // counts as off site.
    Assert::same(['courtroom', false], RoomClassifier::classify('vazební místnost'));
    Assert::same(['video', false], RoomClassifier::classify('videokonferenční místnost'));
    Assert::same(['office', false], RoomClassifier::classify('Kancelář soudce'));
});


test('ordinary courtrooms are the fallback', function () {
    Assert::same(['courtroom', false], RoomClassifier::classify('č. dveří 307 ve III. podlaží'));
    Assert::same(['courtroom', false], RoomClassifier::classify('108PCE - přízemí'));
});
