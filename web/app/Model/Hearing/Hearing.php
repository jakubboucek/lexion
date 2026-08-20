<?php declare(strict_types=1);

namespace App\Model\Hearing;

use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;


/**
 * One hearing harvested from infoJednani (see migration 2026-07-26-00 and
 * docs/infojednani-api.md). The case identity is the one printed at the
 * hearing; `venueCourtKod` is the court of the ROOM, which is only a candidate
 * home court - how far that belief got is in `courtBinding`, and the resolved
 * case (once known) in `caseFileId`.
 *
 * `year` is always four digits, expanded by the importer via
 * CaseYear::fromUpstream(); the upstream two-digit token survives in
 * hearing_observation.raw_json.
 *
 * The two temporal attributes are the only place the refactoring needs the
 * hydrator's escape-hatch attributes: DATE and TIME columns are not plain
 * date-times, and #[Type\Time] is what makes the value export back as `H:i:s`
 * instead of a full date-time string.
 */
class Hearing implements Entity
{
    public int $id;
    public ?int $caseFileId;
    public string $venueCourtKod;
    public string $registryNorm;
    public int $senate;
    public int $bcNumber;
    public int $year;
    #[Type\Date]
    public \DateTimeImmutable $hearingDate;
    #[Type\Time]
    public \DateTimeImmutable $hearingTime;
    /** Verbatim room label as listed by the source; the codelist row is roomId. */
    public ?string $room;
    public ?int $roomId;
    public ?string $hearingType;
    public ?string $judge;
    public bool $cancelled;
    public bool $nonPublic;
    public ?string $result;
    public CourtBinding $courtBinding;
    /** Bumped by every observation from any source = "still scheduled as of". */
    public \DateTimeImmutable $lastSeenAt;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;


    /** The hearing's unique identity (venue court + case + date + minute). */
    public function key(): string
    {
        return HearingKey::venueCaseTime(
            $this->venueCourtKod,
            $this->registryNorm,
            $this->senate,
            $this->bcNumber,
            $this->year,
            $this->hearingDate->format('Y-m-d'),
            $this->timeLabel(),
        );
    }


    /**
     * The identity without the court - what cross-court corroboration against
     * infoSoud matches on (see bin/hearing-bind.php).
     */
    public function caseTimeKey(): string
    {
        return HearingKey::caseTime(
            $this->registryNorm,
            $this->senate,
            $this->bcNumber,
            $this->year,
            $this->hearingDate->format('Y-m-d'),
            $this->timeLabel(),
        );
    }


    /** Start of the hearing as "HH:MM" - the precision both sources publish. */
    public function timeLabel(): string
    {
        return $this->hearingTime->format('H:i');
    }
}
