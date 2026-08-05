<?php declare(strict_types=1);

use App\Model\Hearing\HearingRoomKind;
use App\Model\Hearing\RoomClassifier;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


// Labels taken from the real infoJednani codelist snapshot; the classification
// drives hearing_room.off_site, which will weaken the venue-court guess.
test('off-site places are recognized', function () {
    Assert::same([HearingRoomKind::Prison, true], RoomClassifier::classify('jednací síň ve Věznici Valdice'));
    Assert::same([HearingRoomKind::Hospital, true], RoomClassifier::classify('Psychiatrická nemocnice Bohnice, pavilon 17'));
    Assert::same([HearingRoomKind::Onsite, true], RoomClassifier::classify('na místě samém'));
    Assert::same([HearingRoomKind::Onsite, true], RoomClassifier::classify('MÍSTNÍ OHLEDÁNÍ'));
    Assert::same([HearingRoomKind::External, true], RoomClassifier::classify('výslech mimo budovu soudu'));
});


test('in-house special rooms stay on site', function () {
    // A detention room is inside the courthouse - only an actual prison
    // counts as off site.
    Assert::same([HearingRoomKind::Courtroom, false], RoomClassifier::classify('vazební místnost'));
    Assert::same([HearingRoomKind::Video, false], RoomClassifier::classify('videokonferenční místnost'));
    Assert::same([HearingRoomKind::Office, false], RoomClassifier::classify('Kancelář soudce'));
});


test('ordinary courtrooms are the fallback', function () {
    Assert::same([HearingRoomKind::Courtroom, false], RoomClassifier::classify('č. dveří 307 ve III. podlaží'));
    Assert::same([HearingRoomKind::Courtroom, false], RoomClassifier::classify('108PCE - přízemí'));
});
