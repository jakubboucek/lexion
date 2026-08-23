<?php declare(strict_types=1);

namespace App\Model\Hearing;

use App\Model\Log\LogRunChannel;
use App\Model\Log\LogRunTextFile;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
use App\Model\Spisovka\CaseYear;
use Nette\Database\Explorer;
use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * Imports a finished infoJednani scan (bin/infojednani-scan.php output) into
 * the hearing tables. Extracted from the CLI tool so the logic is deployed
 * with the application (bin/ never reaches the hosting) and callable from any
 * context - the CLI stays a thin argument-parsing wrapper.
 *
 * Two phases: the codelist snapshot (_codelist.json) is upserted into
 * `hearing_room` (each room classified from its label by RoomClassifier),
 * then every stored response is walked and its events merged into `hearing`
 * with the raw event kept in `hearing_observation`.
 *
 * Re-runs are idempotent: hearings key by (venue court, case identity, date,
 * time), observations by (hearing, source, observed_at, room), and a sighting
 * must be strictly fresher to refresh anything - the per-hearing semantics
 * live in HearingMergeRules, shared with the sync so the two writers cannot
 * drift apart.
 *
 * NOT done here (see docs/infojednani-api.md): linking case_file_id and
 * confirming the court binding - infoJednani only knows the court of the
 * ROOM, so every imported hearing stays 'venue_guess' until corroborated
 * (HearingBindService).
 *
 * The whole import is one logged run owned by this service (a dry run is
 * a run too); progress goes to the run's out channel and, when the caller
 * provides one, to a progress callback (the CLI echoes it).
 */
final readonly class HearingScanImportService
{
    /** Files per transaction - a scan is tens of thousands of small writes. */
    private const int CommitEvery = 500;

    /** Progress line cadence, in response files. */
    private const int ProgressEvery = 5000;

    public function __construct(
        private Explorer $db,
        private HearingRepository $hearings,
        private HearingRoomRepository $rooms,
        private LogService $log,
    ) {
    }


    /**
     * @param callable(string): void|null $progress
     * @throws \RuntimeException the scan directory or its codelist snapshot is missing
     */
    public function import(string $scanDir, bool $dryRun = false, ?callable $progress = null): HearingScanImportResult
    {
        $scanDir = rtrim($scanDir, '/');
        // Validated before the run starts: a wrong path is a caller mistake,
        // not a failed import, and must not leave a pending row behind.
        if (!is_dir($scanDir)) {
            throw new \RuntimeException("Scan directory not found: $scanDir");
        }
        $codelistFile = $scanDir . '/_codelist.json';
        if (!is_file($codelistFile)) {
            throw new \RuntimeException("Codelist snapshot not found: $codelistFile");
        }

        $session = $this->log->buildRunSession(HearingLogKind::ScanImport, data: ['scanDir' => $scanDir, 'dryRun' => $dryRun]);
        $out = $session->textFile(LogRunChannel::Out);
        $run = $session->start();
        $notify = function (string $line) use ($out, $progress): void {
            $out->writeLine($line);
            if ($progress !== null) {
                $progress($line);
            }
        };

        try {
            $notify(($dryRun ? '[dry-run] ' : '') . "scan dir: $scanDir");
            $result = new HearingScanImportResult;
            $roomIds = $this->importRooms($codelistFile, $dryRun, $result, $notify);
            $this->importHearings($scanDir, $roomIds, $dryRun, $result, $notify);
        } catch (\Throwable $e) {
            $run->finish(LogStatus::Failed, result: 'error', message: $e->getMessage());
            throw $e;
        }
        $run->finish(LogStatus::Ok, resultData: ['dryRun' => $dryRun] + $result->toLogData());
        return $result;
    }


    /**
     * Phase 1: the room codelist. Returns the (court, label) => id map the
     * hearing phase resolves rooms through; in a dry run, freshly "inserted"
     * rooms carry a placeholder id so lookups still validate.
     *
     * @param callable(string): void $notify
     * @return array<string, int>
     */
    private function importRooms(
        string $codelistFile,
        bool $dryRun,
        HearingScanImportResult $result,
        callable $notify,
    ): array
    {
        $codelist = Json::decode((string) file_get_contents($codelistFile), forceArrays: true);
        $knownCourts = $this->knownCourts();

        $roomIds = [];
        foreach ($this->rooms->findAll() as $room) {
            $roomIds[$room->key()] = $room->id;
        }

        $now = new \DateTimeImmutable;
        foreach ($codelist['soudy'] as $court) {
            $kod = (string) $court['kod'];
            if (!isset($knownCourts[$kod])) {
                $result->roomsSkippedCourt++;
                $notify("unknown court in codelist, skipping: $kod");
                continue;
            }
            foreach ($court['sine'] as $label) {
                $label = (string) $label;
                [$kind, $offSite] = RoomClassifier::classify($label);
                $result->roomKinds[$kind->value] = ($result->roomKinds[$kind->value] ?? 0) + 1;

                // Built even for a room we already know: it is what produces
                // the lookup key, so stored and fresh rooms key identically.
                $room = new HearingRoom;
                $room->courtKod = $kod;
                $room->label = $label;
                $room->kind = $kind;
                $room->offSite = $offSite;
                $room->firstSeen = $now;
                $room->lastSeen = $now;
                $key = $room->key();

                if (isset($roomIds[$key])) {
                    $result->roomsRefreshed++;
                    if (!$dryRun) {
                        $this->rooms->touchSeen($roomIds[$key], $now);
                    }
                    continue;
                }
                $result->roomsInserted++;
                $roomIds[$key] = $dryRun ? -1 : $this->rooms->insert($room)->id;
            }
        }
        ksort($result->roomKinds);
        $notify(sprintf(
            'rooms: %d new, %d refreshed, %d courts skipped',
            $result->roomsInserted,
            $result->roomsRefreshed,
            $result->roomsSkippedCourt,
        ));
        return $roomIds;
    }


    /**
     * Phase 2: the stored responses. Existing hearings are preloaded as
     * entities keyed the same way as the unique index, so a re-import
     * resolves and compares rows without a query per event.
     *
     * @param array<string, int> $roomIds
     * @param callable(string): void $notify
     */
    private function importHearings(
        string $scanDir,
        array $roomIds,
        bool $dryRun,
        HearingScanImportResult $result,
        callable $notify,
    ): void
    {
        $knownCourts = $this->knownCourts();
        $stored = [];
        foreach ($this->hearings->streamAll() as $hearing) {
            $stored[$hearing->key()] = $hearing;
        }

        $files = glob($scanDir . '/*/*/*.json') ?: [];
        sort($files);
        $total = count($files);
        $notify("response files: $total");

        $now = new \DateTimeImmutable;
        $batch = 0;
        if (!$dryRun) {
            $this->db->beginTransaction();
        }

        foreach ($files as $i => $file) {
            $result->files++;
            $parts = explode('/', $file);
            $courtKod = $parts[count($parts) - 3];
            $date = $parts[count($parts) - 2];
            if (!isset($knownCourts[$courtKod])) {
                $result->badFiles++;
                continue;
            }
            try {
                $payload = Json::decode((string) file_get_contents($file), forceArrays: true);
            } catch (JsonException) {
                $result->badFiles++;
                continue;
            }
            $roomLabel = isset($payload['jednaciSin']) ? (string) $payload['jednaciSin'] : null;
            $roomKey = $roomLabel !== null ? HearingRoom::keyOf($courtKod, $roomLabel) : null;
            $roomId = $roomKey !== null ? ($roomIds[$roomKey] ?? null) : null;
            if ($roomKey !== null && $roomId === null) {
                $result->unknownRoom++;
            }
            // platneK is the upstream "valid as of" stamp = when this answer
            // was true.
            $observedAt = isset($payload['platneK'])
                ? new \DateTimeImmutable((string) $payload['platneK'])
                : $now;

            foreach ($payload['udalosti'] ?? [] as $event) {
                $result->events++;
                $incoming = self::hearingFromEvent($courtKod, $date, $event, $roomLabel, $roomId, $observedAt);
                $key = $incoming->key();
                $local = $stored[$key] ?? null;

                if ($local === null) {
                    $result->hearingsNew++;
                    if (!$dryRun) {
                        $incoming = $this->hearings->insert($incoming);
                    } else {
                        $incoming->id = -1; // placeholder so dry runs detect duplicates too
                    }
                    $stored[$key] = $incoming;
                } else {
                    $patch = HearingMergeRules::refreshPatch($local, $incoming);
                    if ($patch !== null) {
                        $result->hearingsRefreshed++;
                        if (!$dryRun) {
                            $this->hearings->update($local->id, $patch);
                        }
                        // Later files of the same run compare against the
                        // refreshed state, exactly as if re-read from the DB.
                        HearingMergeRules::applyToEntity($local, $patch);
                    }
                }

                if ($dryRun) {
                    $result->observations++;
                } else {
                    $observation = new HearingObservation;
                    $observation->hearingId = $stored[$key]->id;
                    $observation->source = ObservationSource::Infojednani;
                    $observation->observedAt = $observedAt;
                    $observation->room = $roomLabel;
                    $observation->rawJson = Json::encode($event);
                    $result->observations += $this->hearings->insertObservationIgnore($observation) ? 1 : 0;
                }
            }

            if (!$dryRun && ++$batch >= self::CommitEvery) {
                $this->db->commit();
                $this->db->beginTransaction();
                $batch = 0;
            }
            if (($i + 1) % self::ProgressEvery === 0 || $i + 1 === $total) {
                $notify(sprintf(
                    'processed %d/%d files (events=%d new=%d refreshed=%d)',
                    $i + 1,
                    $total,
                    $result->events,
                    $result->hearingsNew,
                    $result->hearingsRefreshed,
                ));
            }
        }
        if (!$dryRun) {
            $this->db->commit();
        }
    }


    /** @param array<mixed> $event */
    private static function hearingFromEvent(
        string $courtKod,
        string $date,
        array $event,
        ?string $roomLabel,
        ?int $roomId,
        \DateTimeImmutable $observedAt,
    ): Hearing
    {
        $time = substr((string) $event['cas'], 0, 5);
        $hearing = new Hearing;
        $hearing->venueCourtKod = $courtKod;
        $hearing->registryNorm = (string) $event['druh'];
        $hearing->senate = (int) $event['cislo'];
        $hearing->bcNumber = (int) $event['bcVec'];
        // The scan holds the upstream token (61 = 1961); internally the year
        // is always full, matching case_file.year so the two can be joined.
        $hearing->year = CaseYear::fromUpstream((int) $event['rocnik']);
        $hearing->hearingDate = new \DateTimeImmutable($date);
        // A #[Type\Time] value carries only the wall clock; the hydrator pins
        // it to 0001-01-01 and stores just H:i:s.
        $hearing->hearingTime = new \DateTimeImmutable("0001-01-01 $time");
        $hearing->room = $roomLabel;
        $hearing->roomId = $roomId;
        $hearing->hearingType = isset($event['druhJednani']) ? (string) $event['druhJednani'] : null;
        $hearing->judge = isset($event['resitel']) ? (string) $event['resitel'] : null;
        $hearing->cancelled = ($event['jednaniZruseno'] ?? null) === 'Ano';
        $hearing->nonPublic = ($event['neverejneJednani'] ?? null) === 'Ano';
        $hearing->result = isset($event['vysledek']) ? (string) $event['vysledek'] : null;
        $hearing->lastSeenAt = $observedAt;
        return $hearing;
    }


    /** @return array<string, int> court kod => 1 */
    private function knownCourts(): array
    {
        return $this->db->fetchPairs('SELECT kod, 1 FROM court');
    }
}
