<?php declare(strict_types=1);

namespace App\Presentation\Panel;

use Nette;


/**
 * Common base for all Panel presenters – enforces the login-wall. Presenters
 * reachable without a login (Home, Sign:in/out) must NOT extend this class.
 */
abstract class BasePresenter extends Nette\Application\UI\Presenter
{
    protected function startup(): void
    {
        parent::startup();

        if (!$this->getUser()->isLoggedIn()) {
            $this->redirect(':Sign:in', ['backlink' => $this->storeRequest()]);
        }
    }
}
