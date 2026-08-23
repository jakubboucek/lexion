<?php declare(strict_types=1);

namespace App\Model\Hearing;


/**
 * What one binding run did - the CLI prints it, the log run keeps it as the
 * result payload. Mutable counters: the run fills it phase by phase.
 */
final class HearingBindResult
{
    /** Phase 1: hearings linked to a same-identity case at the venue court. */
    public int $linkedByIdentity = 0;

    /** Phase 2: hearings infoSoud knows about (cached NAR_JED/ZRUS_JED details). */
    public int $infosoudHearings = 0;
    public int $confirmed = 0;
    /** Confirmations that moved the link away from the phase-1 guess. */
    public int $relinked = 0;
    /** Confirmed hearings whose home court differs from the venue court. */
    public int $crossCourt = 0;
    /** Matches rejected because the two sides name different rooms. */
    public int $roomMismatch = 0;


    /** @return array<string, mixed> */
    public function toLogData(): array
    {
        return [
            'linkedByIdentity' => $this->linkedByIdentity,
            'infosoudHearings' => $this->infosoudHearings,
            'confirmed' => $this->confirmed,
            'relinked' => $this->relinked,
            'crossCourt' => $this->crossCourt,
            'roomMismatch' => $this->roomMismatch,
        ];
    }
}
