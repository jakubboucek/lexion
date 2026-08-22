<?php declare(strict_types=1);

namespace App\Model\Sync;

use App\Model\CaseFile\CaseFileRepository;
use App\Model\Codelist\CourtRepository;
use App\Model\Hearing\CourtBinding;
use App\Model\Hearing\Hearing;
use App\Model\Hearing\HearingObservation;
use App\Model\Hearing\HearingRepository;
use App\Model\Hearing\HearingRoom;
use App\Model\Hearing\HearingRoomKind;
use App\Model\Hearing\HearingRoomRepository;
use App\Model\Hearing\ObservationSource;
use App\Model\Log\LogRunJsonlFile;
use Nette\Database\Explorer;


/**
 * Merges `hearing_room` and `hearing` records into the records.
 *
 * WHY HEARINGS MATTER MOST HERE. infoJednani publishes a rolling 30-day
 * window, so a hearing nobody scanned at the time is gone for good - these
 * rows are the one part of the sync that cannot be re-fetched. The merge is
 * built around never losing one.
 *
 * OBSERVATIONS ARE A UNION. An observation is an immutable fact ("this source
 * said this at this moment"), keyed by (hearing, source, observed_at, room),
 * so merging two environments is set union and nothing else - no conflict is
 * even expressible. The insert is the same INSERT IGNORE the scan importer
 * uses, so a repeated file writes nothing.
 *
 * HEARING ATTRIBUTES FOLLOW `last_seen_at`, exactly as bin/infojednani-import
 * does: the fresher sighting wins for type, judge, cancellation, non-public
 * and result. The room is deliberately NOT overwritten once set - a hearing
 * occasionally shows up in two rooms and the first one stays primary, with
 * both preserved as observations.
 *
 * THE BINDING ONLY EVER STRENGTHENS. `court_binding` says how strongly we
 * believe a hearing belongs to the case it links to, and the link travels as
 * the case's identity, never as its id. A guess may be replaced by a
 * confirmation (which may even repoint the hearing to another court's case -
 * infoSoud wins, see bin/hearing-bind.php), but a confirmation is never
 * downgraded or repointed. If the case is not on record here yet, the binding
 * is simply left for a later run: import case files before hearings, or run
 * the hearing parts again afterwards.
 *
 * ROOMS ARE DATA, NOT A CODELIST. They are harvested by the scan and curated
 * by hand, so the two sides legitimately differ. The merge fills gaps and
 * never overwrites curation: a classification lands only where the local kind
 * is still `unknown`, a note only where there is none.
 */
final class HearingMergeService
{
    /**
     * Room ids by (court, label). A hearing import resolves tens of thousands
     * of rooms, so the codelist is read once per run rather than per record;
     * rows this service inserts are added here as they are created.
     *
     * @var array<string, int>|null
     */
    private ?array $roomIds = null;


    public function __construct(
        private readonly Explorer $db,
        private readonly HearingRepository $hearings,
        private readonly HearingRoomRepository $rooms,
        private readonly CaseFileRepository $caseFiles,
        private readonly CourtRepository $courts,
    ) {
    }


    public function mergeRoom(SyncRecord $record, SyncImportReport $report, LogRunJsonlFile $problems): void
    {
        $label = self::roomLabel($record);
        try {
            $incoming = self::readRoom($record);
        } catch (SyncException $e) {
            $this->skipRoom($report, $problems, new SyncProblem($label, SyncProblemReason::InvalidRecord, $e->getMessage()));
            return;
        }
        if ($this->courts->getByKod($incoming->courtKod) === null) {
            $this->skipRoom($report, $problems, new SyncProblem($label, SyncProblemReason::UnknownCodelistKey, 'court ' . $incoming->courtKod));
            return;
        }

        $local = $this->rooms->getByKey($incoming->courtKod, $incoming->label);
        if ($local === null) {
            $stored = $this->rooms->insert($incoming);
            $this->loadRoomIds();
            $this->roomIds[$stored->key()] = $stored->id;
            // Hearings imported before their room keep the verbatim label and
            // a NULL room_id (the schema allows it on purpose); now that the
            // row exists, they can point at it - so the two datasets may be
            // uploaded in either order.
            $this->hearings->linkRoom($stored->id, $stored->courtKod, $stored->label);
            $report->roomsCreated++;
            return;
        }

        $patch = new HearingRoom;
        $changed = false;
        if ($incoming->firstSeen < $local->firstSeen) {
            $patch->firstSeen = $incoming->firstSeen;
            $changed = true;
        }
        // The fresher sighting owns the life cycle: it also decides whether
        // the room is currently retired.
        if (Freshness::isNewer($incoming->lastSeen, $local->lastSeen)) {
            $patch->lastSeen = $incoming->lastSeen;
            $patch->retiredAt = $incoming->retiredAt;
            $changed = true;
        }
        // Curation is hand work - fill a gap, never overwrite an answer.
        if ($local->kind === HearingRoomKind::Unknown && $incoming->kind !== HearingRoomKind::Unknown) {
            $patch->kind = $incoming->kind;
            $patch->offSite = $incoming->offSite;
            $changed = true;
        }
        if ($local->note === null && $incoming->note !== null) {
            $patch->note = $incoming->note;
            $changed = true;
        }
        if ($incoming->createdAt < $local->createdAt) {
            $patch->createdAt = $incoming->createdAt;
            $changed = true;
        }

        if ($changed) {
            $this->rooms->update($local->id, $patch);
            $report->roomsUpdated++;
        } else {
            $report->roomsUnchanged++;
        }
    }


    public function mergeHearing(SyncRecord $record, SyncImportReport $report, LogRunJsonlFile $problems): void
    {
        $label = self::hearingLabel($record);
        try {
            $incoming = self::readHearing($record->child('hearing'));
            $observations = self::readObservations($record->children('observations'));
            $boundCase = $record->optionalChild('boundCase');
        } catch (SyncException $e) {
            $this->skipHearing($report, $problems, new SyncProblem($label, SyncProblemReason::InvalidRecord, $e->getMessage()));
            return;
        }
        if ($this->courts->getByKod($incoming->venueCourtKod) === null) {
            $this->skipHearing($report, $problems, new SyncProblem($label, SyncProblemReason::UnknownCodelistKey, 'court ' . $incoming->venueCourtKod));
            return;
        }

        $incoming->roomId = $incoming->room !== null
            ? $this->roomId(HearingRoom::keyOf($incoming->venueCourtKod, $incoming->room))
            : null;

        $local = $this->hearings->getByIdentity(
            $incoming->venueCourtKod,
            $incoming->registryNorm,
            $incoming->senate,
            $incoming->bcNumber,
            $incoming->year,
            $incoming->hearingDate,
            $incoming->hearingTime,
        );

        $changed = $this->db->getConnection()->transaction(
            fn(): bool => $local === null
                ? $this->create($incoming, $observations, $boundCase, $report)
                : $this->apply($local, $incoming, $observations, $boundCase, $report),
        );

        if ($local === null) {
            $report->hearingsCreated++;
        } elseif ($changed) {
            $report->hearingsUpdated++;
        } else {
            $report->hearingsUnchanged++;
        }
    }


    /** @param list<HearingObservation> $observations */
    private function create(
        Hearing $incoming,
        array $observations,
        ?SyncRecord $boundCase,
        SyncImportReport $report,
    ): bool
    {
        $bound = $this->resolveCase($boundCase);
        $incoming->caseFileId = $bound;
        if ($bound === null) {
            // Without the case the strength of the belief means nothing.
            $incoming->courtBinding = CourtBinding::VenueGuess;
        }
        $stored = $this->hearings->insert($incoming);
        $this->storeObservations($stored->id, $observations, $report);
        return true;
    }


    /** @param list<HearingObservation> $observations */
    private function apply(
        Hearing $local,
        Hearing $incoming,
        array $observations,
        ?SyncRecord $boundCase,
        SyncImportReport $report,
    ): bool
    {
        $patch = new Hearing;
        $changed = false;

        if (Freshness::isNewer($incoming->lastSeenAt, $local->lastSeenAt)) {
            $patch->hearingType = $incoming->hearingType;
            $patch->judge = $incoming->judge;
            $patch->cancelled = $incoming->cancelled;
            $patch->nonPublic = $incoming->nonPublic;
            $patch->result = $incoming->result;
            $patch->lastSeenAt = $incoming->lastSeenAt;
            $changed = true;
        }
        // The primary room is never rewritten, only filled in.
        if ($local->room === null && $incoming->room !== null) {
            $patch->room = $incoming->room;
            $patch->roomId = $incoming->roomId;
            $changed = true;
        } elseif ($local->roomId === null && $incoming->roomId !== null && $local->room === $incoming->room) {
            $patch->roomId = $incoming->roomId;
            $changed = true;
        }
        if ($incoming->createdAt < $local->createdAt) {
            $patch->createdAt = $incoming->createdAt;
            $changed = true;
        }

        if ($this->strengthenBinding($local, $incoming, $boundCase, $patch)) {
            $changed = true;
        }

        if ($changed) {
            $this->hearings->update($local->id, $patch);
        }

        $before = $report->observationsCreated;
        $this->storeObservations($local->id, $observations, $report);
        return $changed || $report->observationsCreated > $before;
    }


    /**
     * Applies the incoming binding to the patch when it is genuinely stronger
     * than what we hold. A confirmation is final: it is never downgraded and
     * never repointed, because both environments confirm from the same
     * infoSoud data and a disagreement means one of them is simply behind.
     */
    private function strengthenBinding(Hearing $local, Hearing $incoming, ?SyncRecord $boundCase, Hearing $patch): bool
    {
        if ($local->courtBinding === CourtBinding::Confirmed) {
            return false;
        }
        // A guess that already found its case is only improved by a
        // confirmation - two guesses have nothing to say to each other.
        if ($local->caseFileId !== null && $incoming->courtBinding !== CourtBinding::Confirmed) {
            return false;
        }
        $bound = $this->resolveCase($boundCase);
        if ($bound === null) {
            return false;
        }
        $patch->caseFileId = $bound;
        $patch->courtBinding = $incoming->courtBinding;
        return true;
    }


    /** @param list<HearingObservation> $observations */
    private function storeObservations(int $hearingId, array $observations, SyncImportReport $report): void
    {
        foreach ($observations as $observation) {
            $observation->hearingId = $hearingId;
            if ($this->hearings->insertObservationIgnore($observation)) {
                $report->observationsCreated++;
            }
        }
    }


    /** Local id of the case a hearing is bound to, if we hold that case. */
    private function resolveCase(?SyncRecord $boundCase): ?int
    {
        if ($boundCase === null) {
            return null;
        }
        $caseFile = $this->caseFiles->getByIdentity(
            $boundCase->text('court'),
            $boundCase->text('registry'),
            $boundCase->number('senate'),
            $boundCase->number('number'),
            $boundCase->number('year'),
        );
        return $caseFile?->id;
    }


    private function roomId(string $key): ?int
    {
        $this->loadRoomIds();
        return $this->roomIds[$key] ?? null;
    }


    private function loadRoomIds(): void
    {
        if ($this->roomIds !== null) {
            return;
        }
        $this->roomIds = [];
        foreach ($this->rooms->findAll() as $room) {
            $this->roomIds[$room->key()] = $room->id;
        }
    }


    private function skipRoom(SyncImportReport $report, LogRunJsonlFile $problems, SyncProblem $problem): void
    {
        $report->addProblem($problem);
        $report->roomsSkipped++;
        $problems->write($problem->toLogData());
    }


    private function skipHearing(SyncImportReport $report, LogRunJsonlFile $problems, SyncProblem $problem): void
    {
        $report->addProblem($problem);
        $report->hearingsSkipped++;
        $problems->write($problem->toLogData());
    }


    private static function readRoom(SyncRecord $record): HearingRoom
    {
        $room = new HearingRoom;
        $room->courtKod = $record->text('court');
        $room->label = $record->text('label');
        $room->kind = $record->enum('kind', HearingRoomKind::class);
        $room->offSite = $record->flag('offSite');
        $room->note = $record->optionalText('note');
        $room->firstSeen = $record->moment('firstSeen');
        $room->lastSeen = $record->moment('lastSeen');
        $room->retiredAt = $record->optionalMoment('retiredAt');
        $room->createdAt = $record->moment('createdAt');
        return $room;
    }


    private static function readHearing(SyncRecord $record): Hearing
    {
        $hearing = new Hearing;
        $hearing->venueCourtKod = $record->text('venueCourt');
        $hearing->registryNorm = $record->text('registry');
        $hearing->senate = $record->number('senate');
        $hearing->bcNumber = $record->number('number');
        $hearing->year = $record->number('year');
        $hearing->hearingDate = self::day($record->text('date'));
        $hearing->hearingTime = self::wallClock($record->text('time'));
        $hearing->room = $record->optionalText('room');
        $hearing->hearingType = $record->optionalText('hearingType');
        $hearing->judge = $record->optionalText('judge');
        $hearing->cancelled = $record->flag('cancelled');
        $hearing->nonPublic = $record->flag('nonPublic');
        $hearing->result = $record->optionalText('result');
        $hearing->courtBinding = $record->enum('courtBinding', CourtBinding::class);
        $hearing->lastSeenAt = $record->moment('lastSeenAt');
        $hearing->createdAt = $record->moment('createdAt');
        return $hearing;
    }


    /**
     * @param list<SyncRecord> $records
     * @return list<HearingObservation>
     */
    private static function readObservations(array $records): array
    {
        $observations = [];
        foreach ($records as $item) {
            $observation = new HearingObservation;
            $observation->source = $item->enum('source', ObservationSource::class);
            $observation->observedAt = $item->moment('observedAt');
            $observation->room = $item->optionalText('room');
            $observation->rawJson = $item->optionalText('rawJson');
            $observation->createdAt = $item->moment('createdAt');
            $observations[] = $observation;
        }
        return $observations;
    }


    private static function day(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new SyncException('Hodnota „date“ není platné datum.', previous: $e);
        }
    }


    /**
     * A #[Type\Time] value carries only the wall clock; the hydrator pins it
     * to 0001-01-01 and stores just the time, so it has to be rebuilt the
     * same way the scan importer builds it.
     */
    private static function wallClock(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable('0001-01-01 ' . $value);
        } catch (\Exception $e) {
            throw new SyncException('Hodnota „time“ není platný čas.', previous: $e);
        }
    }


    private static function roomLabel(SyncRecord $record): string
    {
        $court = $record->raw('court');
        $label = $record->raw('label');
        return sprintf(
            '%s / %s',
            is_scalar($court) ? (string) $court : '?',
            is_scalar($label) ? (string) $label : '?',
        );
    }


    private static function hearingLabel(SyncRecord $record): string
    {
        $hearing = $record->raw('hearing');
        if (!is_array($hearing)) {
            return '?';
        }
        $part = static fn(string $key): string
            => is_scalar($hearing[$key] ?? null) ? (string) $hearing[$key] : '?';
        return sprintf(
            '%s %s %s %s/%s %s %s',
            $part('venueCourt'), $part('senate'), $part('registry'), $part('number'), $part('year'),
            $part('date'), $part('time'),
        );
    }
}
