<?php declare(strict_types=1);

/**
 * Binds hearings to case files on record and, where corroborated, promotes the
 * binding to 'confirmed'. Run inside the dev container:
 *
 *   docker compose exec -w /var/www/html web php bin/hearing-bind.php
 *   docker compose exec -w /var/www/html web php bin/hearing-bind.php --dry-run
 *
 * From infoJednani alone we only know the court of the ROOM (the venue), which
 * is a candidate for the case's home court - the case identity (registry,
 * senate, number, year) is NOT unique without the court, so a hearing must never
 * be linked to a same-identity case at a different court on a guess. Two phases:
 *
 *  1) GUESS - link a hearing to a case file on record with the same identity AT
 *     THE VENUE COURT. The case file unique key makes this at most one row.
 *     court_binding stays 'venue_guess': the link is a belief, not a fact.
 *
 *  2) CONFIRM - corroborate against infoSoud, which is authoritative about the
 *     home court because the case was fetched from that court. A cached
 *     NAR_JED/ZRUS_JED detail carries JED_D_ZAC (start) and JED_SIN (room); when
 *     a hearing has the same identity, date and time, and the rooms agree, the
 *     hearing is that case's hearing: case_file_id is set (even across courts)
 *     and court_binding becomes 'confirmed'.
 *
 * Phase 2 deliberately matches ACROSS courts, because that is the case worth
 * discovering: a hearing held in another court's room (dozadani, prison...)
 * whose home court we could never infer from infoJednani. The room equality is
 * what makes it safe - identity collisions across courts are common (verified),
 * but a collision sharing the identity, the date, the minute AND the room label
 * is not a realistic accident. Hearings where one side has no room fall back to
 * identity + date + time.
 *
 * Options:
 *   --dry-run   report what would change, write nothing
 */

use App\Bootstrap;
use App\Model\Hearing\CourtBinding;
use App\Model\Hearing\Hearing;
use App\Model\Hearing\HearingKey;
use App\Model\Hearing\HearingRepository;
use App\Model\Infosoud\InfosoudHearing;
use Nette\Database\Explorer;
use Nette\Utils\Json;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $opts);

$container = (new Bootstrap)->bootConsoleApplication();
$db = $container->getByType(Explorer::class);
$hearings = $container->getByType(HearingRepository::class);

echo ($dryRun ? "DRY RUN — nothing is written\n\n" : "");

// ---- phase 1: identity match at the venue court -----------------------------

$candidates = $db->fetchAll(
    'SELECT h.id AS hearing_id, p.id AS case_file_id
     FROM hearing h
     JOIN case_file p
       ON p.court_kod = h.venue_court_kod
      AND p.registry_norm = h.registry_norm
      AND p.senate = h.senate
      AND p.bc_number = h.bc_number
      AND p.year = h.year
     WHERE h.case_file_id IS NULL',
);
printf("Phase 1 — identity match at venue court: %d hearing(s)\n", count($candidates));

// What phase 1 links (or would link, in a dry run). Phase 2 consults this map
// so the dry run reports the same relinked/confirmed numbers as a real run.
$guessed = [];
foreach ($candidates as $row) {
    $guessed[(int) $row->hearing_id] = (int) $row->case_file_id;
}
if (!$dryRun) {
    $db->getConnection()->transaction(static function () use ($hearings, $candidates): void {
        foreach ($candidates as $row) {
            $changes = new Hearing;
            $changes->caseFileId = (int) $row->case_file_id;
            $hearings->update((int) $row->hearing_id, $changes);
        }
    });
}

// ---- phase 2: corroborate against infoSoud hearing details ------------------

// Every hearing infoSoud knows about, from the cached NAR_JED/ZRUS_JED details.
$details = $db->fetchAll(
    "SELECT p.id AS case_file_id, p.court_kod, p.registry_norm, p.senate, p.bc_number, p.year,
            e.detail_json
     FROM case_file_event e
     JOIN case_file p ON p.id = e.case_file_id
     WHERE e.event_code IN ('NAR_JED', 'ZRUS_JED') AND e.detail_json IS NOT NULL",
);

/** @var array<string, array{case_file_id:int, court:string, room:?string}> $infosoud */
$infosoud = [];
foreach ($details as $row) {
    try {
        $detail = Json::decode((string) $row->detail_json, forceArrays: true);
    } catch (Nette\Utils\JsonException) {
        continue;
    }
    // Shared parser keeps the attribute semantics (incl. '-' meaning "not
    // stated" for the room) identical to what the web renders.
    $hearing = InfosoudHearing::fromEventDetail($detail);
    if ($hearing === null || $hearing->startsAt === null) {
        continue;
    }
    $key = HearingKey::caseTime(
        (string) $row->registry_norm, (int) $row->senate, (int) $row->bc_number, (int) $row->year,
        $hearing->startsAt->format('Y-m-d'), $hearing->startsAt->format('H:i'),
    );
    // Same case, same minute, two records (NAR_JED + its ZRUS_JED) - identical
    // for our purposes, so first one wins.
    $infosoud[$key] ??= [
        'case_file_id' => (int) $row->case_file_id,
        'court' => (string) $row->court_kod,
        'room' => $hearing->room,
    ];
}
printf("Phase 2 — hearings known from infoSoud details: %d\n", count($infosoud));

$stats = ['confirmed' => 0, 'room_mismatch' => 0, 'cross_court' => 0, 'relinked' => 0];
$updates = []; // hearing id => patch entity, applied in one transaction below
foreach ($hearings->streamUnconfirmed() as $hearing) {
    $match = $infosoud[$hearing->caseTimeKey()] ?? null;
    if ($match === null) {
        continue;
    }
    // Rooms must agree when both sides have one: that is what separates a real
    // corroboration from a same-identity case at an unrelated court.
    if ($match['room'] !== null && $hearing->room !== null && $match['room'] !== $hearing->room) {
        $stats['room_mismatch']++;
        printf(
            "  ! room mismatch, not confirmed: %s %d %s %d/%d %s %s | infoJednani=%s | infoSoud=%s\n",
            $hearing->venueCourtKod, $hearing->senate, $hearing->registryNorm,
            $hearing->bcNumber, $hearing->year, $hearing->hearingDate->format('Y-m-d'), $hearing->timeLabel(),
            $hearing->room, $match['room'],
        );
        continue;
    }

    // In a real run the phase-1 link is already in the row; in a dry run it
    // exists only in $guessed - use whichever applies so both report alike.
    $linked = $hearing->caseFileId ?? ($guessed[$hearing->id] ?? null);
    $update = new Hearing;
    $update->courtBinding = CourtBinding::Confirmed;
    if ($linked !== $match['case_file_id']) {
        // infoSoud wins over the phase-1 guess: it knows the home court.
        if ($linked !== null) {
            $stats['relinked']++;
        }
        $update->caseFileId = $match['case_file_id'];
    }
    if ($match['court'] !== $hearing->venueCourtKod) {
        $stats['cross_court']++;
        printf(
            "  * home court differs from venue: %s %d %s %d/%d %s — venue=%s, case at %s (room: %s)\n",
            $match['court'], $hearing->senate, $hearing->registryNorm, $hearing->bcNumber, $hearing->year,
            $hearing->hearingDate->format('Y-m-d'), $hearing->venueCourtKod, $match['court'],
            $hearing->room ?? '-',
        );
    }
    $stats['confirmed']++;
    $updates[$hearing->id] = $update;
}
if (!$dryRun && $updates !== []) {
    $db->getConnection()->transaction(static function () use ($hearings, $updates): void {
        foreach ($updates as $id => $update) {
            $hearings->update($id, $update);
        }
    });
}

echo "\nDone.\n";
printf("  linked by identity (venue_guess) %d\n", count($candidates));
printf("  confirmed via infoSoud           %d\n", $stats['confirmed']);
if ($stats['relinked'] > 0) {
    printf("  re-linked to another case        %d\n", $stats['relinked']);
}
if ($stats['cross_court'] > 0) {
    printf("  home court != venue court        %d\n", $stats['cross_court']);
}
if ($stats['room_mismatch'] > 0) {
    printf("  rejected on room mismatch        %d\n", $stats['room_mismatch']);
}
