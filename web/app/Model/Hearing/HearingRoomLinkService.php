<?php declare(strict_types=1);

namespace App\Model\Hearing;

use App\Model\Log\LogRunChannel;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;


/**
 * Back-fills hearing -> room links across the whole table: hearings whose
 * room label exists in the codelist but whose room_id is NULL (typically
 * hearings imported before their rooms - the schema tolerates that on
 * purpose, see migration 2026-07-26-01).
 *
 * A repair action of the integrity page (docs/navrh-integrita-dat.md,
 * step 4): idempotent, purely additive - no existing link is ever changed or
 * cleared - and never a side effect of an import. Owns its logged run, dry
 * run included.
 */
final readonly class HearingRoomLinkService
{
    public function __construct(
        private HearingRepository $hearings,
        private LogService $log,
    ) {
    }


    /** Returns how many links were created (or would be, in a dry run). */
    public function linkAll(bool $dryRun = false): int
    {
        $session = $this->log->buildRunSession(HearingLogKind::RoomLink, data: ['dryRun' => $dryRun]);
        $out = $session->textFile(LogRunChannel::Out);
        $run = $session->start();

        try {
            $linkable = $this->hearings->countRoomLinkable();
            $out->writeLine(($dryRun ? '[dry-run] ' : '') . "linkable hearings: $linkable");
            $linked = 0;
            if (!$dryRun && $linkable > 0) {
                $linked = $this->hearings->linkAllRooms();
                $out->writeLine("linked: $linked");
            }
        } catch (\Throwable $e) {
            $run->finish(LogStatus::Failed, result: 'error', message: $e->getMessage());
            throw $e;
        }
        $run->finish(LogStatus::Ok, resultData: ['dryRun' => $dryRun, 'linkable' => $linkable, 'linked' => $linked]);
        return $dryRun ? $linkable : $linked;
    }
}
