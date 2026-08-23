<?php declare(strict_types=1);

namespace App\Model\Hearing;


/**
 * What one scan import did - the CLI prints it, the log run keeps it as the
 * result payload. Mutable counters: the run fills it as it walks the scan.
 */
final class HearingScanImportResult
{
    public int $roomsInserted = 0;
    public int $roomsRefreshed = 0;
    public int $roomsSkippedCourt = 0;
    /** @var array<string, int> classified rooms per HearingRoomKind value */
    public array $roomKinds = [];

    public int $files = 0;
    /** Unreadable files and files of courts the codelist does not know. */
    public int $badFiles = 0;
    public int $events = 0;
    public int $hearingsNew = 0;
    public int $hearingsRefreshed = 0;
    public int $observations = 0;
    /** Responses whose room label is missing from the room codelist. */
    public int $unknownRoom = 0;


    /** @return array<string, mixed> */
    public function toLogData(): array
    {
        return [
            'rooms' => [
                'inserted' => $this->roomsInserted,
                'refreshed' => $this->roomsRefreshed,
                'skippedCourt' => $this->roomsSkippedCourt,
            ],
            'files' => $this->files,
            'badFiles' => $this->badFiles,
            'events' => $this->events,
            'hearingsNew' => $this->hearingsNew,
            'hearingsRefreshed' => $this->hearingsRefreshed,
            'observations' => $this->observations,
            'unknownRoom' => $this->unknownRoom,
        ];
    }
}
