<?php declare(strict_types=1);

/**
 * Binds hearings to cached proceedings and, where corroborated, promotes the
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
 *  1) GUESS - link a hearing to a cached proceeding with the same identity AT
 *     THE VENUE COURT. The proceeding unique key makes this at most one row.
 *     court_binding stays 'venue_guess': the link is a belief, not a fact.
 *
 *  2) CONFIRM - corroborate against infoSoud, which is authoritative about the
 *     home court because the case was fetched from that court. A cached
 *     NAR_JED/ZRUS_JED detail carries JED_D_ZAC (start) and JED_SIN (room); when
 *     a hearing has the same identity, date and time, and the rooms agree, the
 *     hearing is that case's hearing: proceeding_id is set (even across courts)
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
use Nette\Database\Explorer;
use Nette\Utils\Json;

require __DIR__ . '/../web/vendor/autoload.php';

$opts = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $opts);

$container = (new Bootstrap)->bootConsoleApplication();
$db = $container->getByType(Explorer::class);

echo ($dryRun ? "DRY RUN — nothing is written\n\n" : "");

// ---- phase 1: identity match at the venue court -----------------------------

$candidates = $db->fetchAll(
    'SELECT h.id AS hearing_id, p.id AS proceeding_id
     FROM hearing h
     JOIN proceeding p
       ON p.court_kod = h.venue_court_kod
      AND p.registry_norm = h.registry_norm
      AND p.senate = h.senate
      AND p.bc_number = h.bc_number
      AND p.year = h.year
     WHERE h.proceeding_id IS NULL',
);
printf("Phase 1 — identity match at venue court: %d hearing(s)\n", count($candidates));
if (!$dryRun) {
    foreach ($candidates as $row) {
        $db->query('UPDATE hearing SET ? WHERE id = ?', ['proceeding_id' => $row->proceeding_id], $row->hearing_id);
    }
}

// ---- phase 2: corroborate against infoSoud hearing details ------------------

// Every hearing infoSoud knows about, from the cached NAR_JED/ZRUS_JED details.
$details = $db->fetchAll(
    "SELECT p.id AS proceeding_id, p.court_kod, p.registry_norm, p.senate, p.bc_number, p.year,
            e.detail_json
     FROM proceeding_event e
     JOIN proceeding p ON p.id = e.proceeding_id
     WHERE e.event_code IN ('NAR_JED', 'ZRUS_JED') AND e.detail_json IS NOT NULL",
);

/** @var array<string, array{proceeding_id:int, court:string, room:?string}> $infosoud */
$infosoud = [];
foreach ($details as $row) {
    try {
        $detail = Json::decode((string) $row->detail_json, forceArrays: true);
    } catch (Nette\Utils\JsonException) {
        continue;
    }
    $jed = [];
    foreach ($detail['atributy'] ?? [] as $attribute) {
        if (isset($attribute['typ'])) {
            $jed[(string) $attribute['typ']] = trim((string) ($attribute['hodnota'] ?? ''));
        }
    }
    $startsAt = DateTimeImmutable::createFromFormat('!d.m.Y H:i', $jed['JED_D_ZAC'] ?? '');
    if ($startsAt === false) {
        continue;
    }
    $key = implode('|', [
        $row->registry_norm, (int) $row->senate, (int) $row->bc_number, (int) $row->year,
        $startsAt->format('Y-m-d'), $startsAt->format('H:i'),
    ]);
    // Same case, same minute, two records (NAR_JED + its ZRUS_JED) - identical
    // for our purposes, so first one wins.
    $infosoud[$key] ??= [
        'proceeding_id' => (int) $row->proceeding_id,
        'court' => (string) $row->court_kod,
        'room' => ($jed['JED_SIN'] ?? '') !== '' ? $jed['JED_SIN'] : null,
    ];
}
printf("Phase 2 — hearings known from infoSoud details: %d\n", count($infosoud));

$stats = ['confirmed' => 0, 'room_mismatch' => 0, 'cross_court' => 0, 'relinked' => 0];
foreach ($db->fetchAll(
    "SELECT id, venue_court_kod, registry_norm, senate, bc_number, year, hearing_date, hearing_time,
            room, proceeding_id, court_binding
     FROM hearing WHERE court_binding <> 'confirmed'",
) as $hearing) {
    $time = $hearing->hearing_time instanceof DateInterval
        ? sprintf('%02d:%02d', $hearing->hearing_time->h, $hearing->hearing_time->i)
        : substr((string) $hearing->hearing_time, 0, 5);
    $key = implode('|', [
        $hearing->registry_norm, (int) $hearing->senate, (int) $hearing->bc_number, (int) $hearing->year,
        $hearing->hearing_date->format('Y-m-d'), $time,
    ]);
    $match = $infosoud[$key] ?? null;
    if ($match === null) {
        continue;
    }
    // Rooms must agree when both sides have one: that is what separates a real
    // corroboration from a same-identity case at an unrelated court.
    if ($match['room'] !== null && $hearing->room !== null && $match['room'] !== $hearing->room) {
        $stats['room_mismatch']++;
        printf(
            "  ! room mismatch, not confirmed: %s %d %s %d/%d %s %s | infoJednani=%s | infoSoud=%s\n",
            $hearing->venue_court_kod, $hearing->senate, $hearing->registry_norm,
            $hearing->bc_number, $hearing->year, $hearing->hearing_date->format('Y-m-d'), $time,
            $hearing->room, $match['room'],
        );
        continue;
    }

    $update = ['court_binding' => 'confirmed'];
    if ((int) ($hearing->proceeding_id ?? 0) !== $match['proceeding_id']) {
        // infoSoud wins over the phase-1 guess: it knows the home court.
        if ($hearing->proceeding_id !== null) {
            $stats['relinked']++;
        }
        $update['proceeding_id'] = $match['proceeding_id'];
    }
    if ($match['court'] !== $hearing->venue_court_kod) {
        $stats['cross_court']++;
        printf(
            "  * home court differs from venue: %s %d %s %d/%d %s — venue=%s, case at %s (room: %s)\n",
            $match['court'], $hearing->senate, $hearing->registry_norm, $hearing->bc_number, $hearing->year,
            $hearing->hearing_date->format('Y-m-d'), $hearing->venue_court_kod, $match['court'],
            $hearing->room ?? '-',
        );
    }
    $stats['confirmed']++;
    if (!$dryRun) {
        $db->query('UPDATE hearing SET ? WHERE id = ?', $update, $hearing->id);
    }
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
