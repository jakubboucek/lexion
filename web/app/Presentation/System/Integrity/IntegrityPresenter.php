<?php declare(strict_types=1);

namespace App\Presentation\System\Integrity;

use App\Model\Hearing\HearingBindService;
use App\Model\Hearing\HearingRoomLinkService;
use App\Model\Integrity\IntegrityCategory;
use App\Model\Integrity\IntegrityCheckResult;
use App\Model\Integrity\IntegrityService;
use App\Presentation\Error\UserFacingError;
use App\Presentation\System\BasePresenter;


/**
 * Data-integrity checks and their safe repairs (docs/navrh-integrita-dat.md,
 * steps 1 and 4). Rendering the page runs the checks - cheap read-only
 * queries - and is deliberately not logged; the explicit "record" signal
 * writes the current state as one instant log record so trends survive.
 *
 * Repairs are dispatched by the slug an IntegrityCheck declares. Only
 * additive, idempotent operations qualify (both today back-fill missing
 * links); each one runs as its own logged run inside its service, dry run
 * included. Destructive repairs (a reprojection) have no button on purpose.
 */
final class IntegrityPresenter extends BasePresenter
{
    public function __construct(
        private readonly IntegrityService $integrity,
        private readonly HearingRoomLinkService $roomLink,
        private readonly HearingBindService $bind,
    ) {
        parent::__construct();
    }


    public function renderDefault(): void
    {
        $results = $this->integrity->runAll();

        $sections = [
            IntegrityCategory::Discrepancy->value => [],
            IntegrityCategory::Incompleteness->value => [],
        ];
        $defects = 0;
        foreach ($results as $result) {
            $sections[$result->check->category->value][] = $this->resultView($result);
            $defects += $result->isDefect() ? 1 : 0;
        }

        $this->template->discrepancies = $sections[IntegrityCategory::Discrepancy->value];
        $this->template->incompleteness = $sections[IntegrityCategory::Incompleteness->value];
        $this->template->defects = $defects;
    }


    /** Runs one safe repair action (or its dry run) and reports the outcome. */
    public function handleRepair(string $repair, bool $dry = false): void
    {
        switch ($repair) {
            case 'link-rooms':
                $count = $this->roomLink->linkAll($dry);
                $this->flashMessage($dry
                    ? "Nasucho: dopárovalo by se $count odkazů na síně."
                    : "Dopárováno $count odkazů na síně.");
                break;
            case 'bind-hearings':
                $result = $this->bind->bind($dry);
                $this->flashMessage(sprintf(
                    '%sNavázáno %d jednání podle soudu síně, %d potvrzeno proti infoSoudu%s.',
                    $dry ? 'Nasucho: ' : '',
                    $result->linkedByIdentity,
                    $result->confirmed,
                    $result->relinked > 0 ? " (z toho {$result->relinked} převázáno)" : '',
                ));
                break;
            default:
                throw new UserFacingError('Neznámá oprava.');
        }
        $this->redirect('this');
    }


    /** Writes the current counts into the application log. */
    public function handleRecord(): void
    {
        $this->integrity->record($this->integrity->runAll());
        $this->flashMessage('Stav kontrol byl zapsán do aplikačního logu.');
        $this->redirect('this');
    }


    /** @return array{slug: string, title: string, description: string, count: int, samples: list<string>, defect: bool, repair: ?string} */
    private function resultView(IntegrityCheckResult $result): array
    {
        return [
            'slug' => $result->check->slug,
            'title' => $result->check->title,
            'description' => $result->check->description,
            'count' => $result->count,
            'samples' => $result->samples,
            'defect' => $result->isDefect(),
            'repair' => $result->check->repair,
        ];
    }
}
