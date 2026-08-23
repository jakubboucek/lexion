<?php declare(strict_types=1);

namespace App\Model\Hearing;

use App\Model\Infosoud\InfosoudHearing;
use App\Model\Log\LogRunChannel;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
use Nette\Database\Explorer;
use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * Binds hearings to case files on record and, where corroborated, promotes
 * the binding to 'confirmed'. Extracted from the CLI tool so the logic is
 * deployed with the application (bin/ never reaches the hosting) and callable
 * from any context - the CLI stays a thin wrapper.
 *
 * From infoJednani alone we only know the court of the ROOM (the venue),
 * which is a candidate for the case's home court - the case identity is NOT
 * unique without the court, so a hearing must never be linked to a
 * same-identity case at a different court on a guess. Two phases:
 *
 *  1) GUESS - link a hearing to a case file on record with the same identity
 *     AT THE VENUE COURT (the case file unique key makes this at most one
 *     row). court_binding stays 'venue_guess': the link is a belief.
 *
 *  2) CONFIRM - corroborate against infoSoud, authoritative about the home
 *     court. A cached NAR_JED/ZRUS_JED detail carries the start and the room;
 *     when a hearing has the same identity, date and time, and the rooms
 *     agree, case_file_id is set (even across courts) and court_binding
 *     becomes 'confirmed'. The cross-court match is the point - a hearing
 *     held in another court's room (dozadani, prison...) whose home court
 *     infoJednani could never tell us; the room equality is what makes it
 *     safe, and hearings where one side has no room fall back to
 *     identity + date + time.
 *
 * The whole binding is one logged run owned by this service (a dry run is
 * a run too); notable findings go to the run's out channel and, when the
 * caller provides one, to a progress callback (the CLI echoes it).
 */
final readonly class HearingBindService
{
    public function __construct(
        private Explorer $db,
        private HearingRepository $hearings,
        private LogService $log,
    ) {
    }


    /** @param callable(string): void|null $progress */
    public function bind(bool $dryRun = false, ?callable $progress = null): HearingBindResult
    {
        $session = $this->log->buildRunSession(HearingLogKind::Bind, data: ['dryRun' => $dryRun]);
        $out = $session->textFile(LogRunChannel::Out);
        $run = $session->start();
        $notify = function (string $line) use ($out, $progress): void {
            $out->writeLine($line);
            if ($progress !== null) {
                $progress($line);
            }
        };

        try {
            $result = new HearingBindResult;
            $guessed = $this->linkByIdentity($dryRun, $result, $notify);
            $this->confirmAgainstInfosoud($guessed, $dryRun, $result, $notify);
        } catch (\Throwable $e) {
            $run->finish(LogStatus::Failed, result: 'error', message: $e->getMessage());
            throw $e;
        }
        $run->finish(LogStatus::Ok, resultData: ['dryRun' => $dryRun] + $result->toLogData());
        return $result;
    }


    /**
     * Phase 1. Returns what it linked (or would link, in a dry run) so phase
     * 2 reports the same relinked/confirmed numbers either way.
     *
     * @param callable(string): void $notify
     * @return array<int, int> hearing id => case file id
     */
    private function linkByIdentity(bool $dryRun, HearingBindResult $result, callable $notify): array
    {
        $candidates = $this->db->fetchAll(
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
        $result->linkedByIdentity = count($candidates);
        $notify(($dryRun ? '[dry-run] ' : '') . 'phase 1: identity match at venue court: ' . count($candidates) . ' hearing(s)');

        $guessed = [];
        foreach ($candidates as $row) {
            $guessed[(int) $row->hearing_id] = (int) $row->case_file_id;
        }
        if (!$dryRun && $candidates !== []) {
            $this->db->getConnection()->transaction(function () use ($candidates): void {
                foreach ($candidates as $row) {
                    $changes = new Hearing;
                    $changes->caseFileId = (int) $row->case_file_id;
                    $this->hearings->update((int) $row->hearing_id, $changes);
                }
            });
        }
        return $guessed;
    }


    /**
     * Phase 2.
     *
     * @param array<int, int> $guessed phase-1 links (hearing id => case file id)
     * @param callable(string): void $notify
     */
    private function confirmAgainstInfosoud(
        array $guessed,
        bool $dryRun,
        HearingBindResult $result,
        callable $notify,
    ): void
    {
        $infosoud = $this->infosoudHearings();
        $result->infosoudHearings = count($infosoud);
        $notify('phase 2: hearings known from infoSoud details: ' . count($infosoud));

        $updates = []; // hearing id => patch entity, applied in one transaction below
        foreach ($this->hearings->streamUnconfirmed() as $hearing) {
            $match = $infosoud[$hearing->caseTimeKey()] ?? null;
            if ($match === null) {
                continue;
            }
            // Rooms must agree when both sides have one: that is what
            // separates a real corroboration from a same-identity case at an
            // unrelated court.
            if ($match['room'] !== null && $hearing->room !== null && $match['room'] !== $hearing->room) {
                $result->roomMismatch++;
                $notify(sprintf(
                    'room mismatch, not confirmed: %s %d %s %d/%d %s %s | infoJednani=%s | infoSoud=%s',
                    $hearing->venueCourtKod, $hearing->senate, $hearing->registryNorm,
                    $hearing->bcNumber, $hearing->year, $hearing->hearingDate->format('Y-m-d'), $hearing->timeLabel(),
                    $hearing->room, $match['room'],
                ));
                continue;
            }

            // In a real run the phase-1 link is already in the row; in a dry
            // run it exists only in $guessed - use whichever applies so both
            // report alike.
            $linked = $hearing->caseFileId ?? ($guessed[$hearing->id] ?? null);
            $update = new Hearing;
            $update->courtBinding = CourtBinding::Confirmed;
            if ($linked !== $match['case_file_id']) {
                // infoSoud wins over the phase-1 guess: it knows the home court.
                if ($linked !== null) {
                    $result->relinked++;
                }
                $update->caseFileId = $match['case_file_id'];
            }
            if ($match['court'] !== $hearing->venueCourtKod) {
                $result->crossCourt++;
                $notify(sprintf(
                    'home court differs from venue: %s %d %s %d/%d %s — venue=%s, case at %s (room: %s)',
                    $match['court'], $hearing->senate, $hearing->registryNorm, $hearing->bcNumber, $hearing->year,
                    $hearing->hearingDate->format('Y-m-d'), $hearing->venueCourtKod, $match['court'],
                    $hearing->room ?? '-',
                ));
            }
            $result->confirmed++;
            $updates[$hearing->id] = $update;
        }
        if (!$dryRun && $updates !== []) {
            $this->db->getConnection()->transaction(function () use ($updates): void {
                foreach ($updates as $id => $update) {
                    $this->hearings->update($id, $update);
                }
            });
        }
    }


    /**
     * Every hearing infoSoud knows about, keyed by case identity + minute,
     * from the cached NAR_JED/ZRUS_JED details.
     *
     * @return array<string, array{case_file_id: int, court: string, room: ?string}>
     */
    private function infosoudHearings(): array
    {
        $details = $this->db->fetchAll(
            "SELECT p.id AS case_file_id, p.court_kod, p.registry_norm, p.senate, p.bc_number, p.year,
                    e.detail_json
             FROM case_file_event e
             JOIN case_file p ON p.id = e.case_file_id
             WHERE e.event_code IN ('NAR_JED', 'ZRUS_JED') AND e.detail_json IS NOT NULL",
        );

        $infosoud = [];
        foreach ($details as $row) {
            try {
                $detail = Json::decode((string) $row->detail_json, forceArrays: true);
            } catch (JsonException) {
                continue;
            }
            // Shared parser keeps the attribute semantics (incl. '-' meaning
            // "not stated" for the room) identical to what the web renders.
            $hearing = InfosoudHearing::fromEventDetail($detail);
            if ($hearing === null || $hearing->startsAt === null) {
                continue;
            }
            $key = HearingKey::caseTime(
                (string) $row->registry_norm, (int) $row->senate, (int) $row->bc_number, (int) $row->year,
                $hearing->startsAt->format('Y-m-d'), $hearing->startsAt->format('H:i'),
            );
            // Same case, same minute, two records (NAR_JED + its ZRUS_JED) -
            // identical for our purposes, so first one wins.
            $infosoud[$key] ??= [
                'case_file_id' => (int) $row->case_file_id,
                'court' => (string) $row->court_kod,
                'room' => $hearing->room,
            ];
        }
        return $infosoud;
    }
}
