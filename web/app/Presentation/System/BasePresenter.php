<?php declare(strict_types=1);

namespace App\Presentation\System;

use Nette;


/**
 * Common base for the System section - operator tools that run maintenance on
 * the whole database (data sync today, more later). Login-walled exactly like
 * the Panel: the same rule, deliberately spelled out again rather than pulled
 * into a shared parent, so each section owns its own gate.
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
