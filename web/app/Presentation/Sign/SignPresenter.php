<?php declare(strict_types=1);

namespace App\Presentation\Sign;

use Nette;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;


/**
 * Login / logout gateway for the logged-in user area. Lives outside the Panel
 * module – it is the gate, not a protected page.
 */
final class SignPresenter extends Nette\Application\UI\Presenter
{
    #[Persistent]
    public string $backlink = '';


    public function actionIn(): void
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect(':Panel:Dashboard:default');
        }
    }


    public function actionOut(): void
    {
        $this->getUser()->logout(true);
        $this->flashMessage('Byl jsi odhlášen.');
        $this->redirect('in');
    }


    protected function createComponentSignInForm(): Form
    {
        $form = new Form;
        $form->addEmail('email', 'E-mail')
            ->setRequired('Zadej e-mail.');
        $form->addPassword('password', 'Heslo')
            ->setRequired('Zadej heslo.');
        $form->addSubmit('send', 'Přihlásit se');

        $form->onSuccess[] = $this->signInFormSucceeded(...);

        return $form;
    }


    private function signInFormSucceeded(Form $form, \stdClass $data): void
    {
        try {
            $this->getUser()->login($data->email, $data->password);
        } catch (AuthenticationException $e) {
            $form->addError($e->getMessage());
            return;
        }

        $this->restoreRequest($this->backlink);
        $this->redirect(':Panel:Dashboard:default');
    }
}
