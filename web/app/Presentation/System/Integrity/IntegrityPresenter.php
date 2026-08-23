<?php declare(strict_types=1);

namespace App\Presentation\System\Integrity;

use App\Model\Integrity\IntegrityCategory;
use App\Model\Integrity\IntegrityCheckResult;
use App\Model\Integrity\IntegrityService;
use App\Presentation\System\BasePresenter;


/**
 * Read-only data-integrity checks (docs/navrh-integrita-dat.md, step 1).
 * Rendering the page runs the checks - they are cheap read-only queries -
 * and is deliberately not logged; the explicit "record" signal writes the
 * current state as one instant log record so trends survive.
 */
final class IntegrityPresenter extends BasePresenter
{
    public function __construct(
        private readonly IntegrityService $integrity,
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


    /** Writes the current counts into the application log. */
    public function handleRecord(): void
    {
        $this->integrity->record($this->integrity->runAll());
        $this->flashMessage('Stav kontrol byl zapsán do aplikačního logu.');
        $this->redirect('this');
    }


    /** @return array{slug: string, title: string, description: string, count: int, samples: list<string>, defect: bool} */
    private function resultView(IntegrityCheckResult $result): array
    {
        return [
            'slug' => $result->check->slug,
            'title' => $result->check->title,
            'description' => $result->check->description,
            'count' => $result->count,
            'samples' => $result->samples,
            'defect' => $result->isDefect(),
        ];
    }
}
