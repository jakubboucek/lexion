<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;


/**
 * One event of a case file's timeline (table `case_file_event`, see
 * docs/analyza-udalosti.md). Rows are maintained by
 * CaseFileProjectionService; URLs and internal references use the surrogate
 * `id` only - `eventOrder` (upstream "poradi") serves sync pairing and display
 * ordering and is not stable enough to address an event by.
 *
 * The ref* columns carry the owner case of a FOREIGN event (an appeal at
 * another court listing itself in this timeline); all NULL means the event
 * belongs to this case.
 *
 * `own_event_order` has no property here - the database generates it so the
 * unique key can skip foreign events.
 */
class CaseFileEvent implements Entity
{
    public int $id;
    public int $caseFileId;
    /** Feed that produced the row; see DataSource for the known values. */
    public string $source;
    public string $eventCode;
    public ?int $eventOrder;
    /** udalostId (CEPR only, may be composite "id;sub"). */
    public ?string $upstreamId;
    #[Type\Date]
    public ?\DateTimeImmutable $eventDate;
    public bool $cancelled;
    public ?string $refCourtKod;
    public ?string $refRegistryNorm;
    public ?int $refSenate;
    public ?int $refBcNumber;
    public ?int $refYear;
    /** Raw upstream detail, kept as a snapshot string - never typed. */
    public ?string $detailJson;
    public ?\DateTimeImmutable $detailFetchedAt;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;


    /** An event of another case listed in this timeline (appeal, transfer...). */
    public function isForeign(): bool
    {
        return $this->refCourtKod !== null || $this->refRegistryNorm !== null;
    }


    /**
     * Copies the owner-case reference from another row. The sync resolves that
     * reference once per upstream event and shares it between the event and
     * the relation projection, so the two can never read znackaId differently.
     */
    public function takeOwnerRefFrom(self $source): void
    {
        $this->refCourtKod = $source->refCourtKod;
        $this->refRegistryNorm = $source->refRegistryNorm;
        $this->refSenate = $source->refSenate;
        $this->refBcNumber = $source->refBcNumber;
        $this->refYear = $source->refYear;
    }


    /**
     * Key pairing a stored event with an incoming one during a sync. The code
     * and poradi alone are not enough: one case can carry many foreign events
     * of the same code AND poradi that differ only in the owner case (NC 3601
     * lists 8x ODVOLANI, all with poradi 1), so the owner identity is part of
     * the key. Both sides of a sync derive it here, so they cannot drift.
     */
    public function pairingKey(): string
    {
        return implode('|', [
            $this->eventCode,
            $this->eventOrder ?? '',
            $this->refCourtKod ?? '',
            $this->refRegistryNorm ?? '',
            $this->refSenate ?? '',
            $this->refBcNumber ?? '',
            $this->refYear ?? '',
        ]);
    }
}
