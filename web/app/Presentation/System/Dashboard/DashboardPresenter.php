<?php declare(strict_types=1);

namespace App\Presentation\System\Dashboard;

use App\Model\CaseFile\CaseFileRepository;
use App\Presentation\System\BasePresenter;


/** Landing page of the System section: what the operator tools are and where. */
final class DashboardPresenter extends BasePresenter
{
    public function __construct(
        private readonly CaseFileRepository $caseFiles,
    ) {
        parent::__construct();
    }


    public function renderDefault(): void
    {
        $this->template->caseFiles = $this->caseFiles->countAll();
    }
}
