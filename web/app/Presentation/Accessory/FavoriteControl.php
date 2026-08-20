<?php declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Model\CaseFile\CaseFile;
use App\Model\Favorite\Favorite;
use App\Model\Favorite\FavoriteRepository;
use App\Presentation\Error\UserFacingError;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Http\IResponse;


/**
 * The bookmark star of one case: the outline star opens a modal asking for an
 * optional custom name, the filled one a removal confirmation.
 *
 * Lived in SpisPresenter and its header template (tech-debt ST-1 step 4);
 * as a component it can go wherever a case is shown. The caller passes the
 * bookmark it already knows about, so the state is read once per request.
 */
final class FavoriteControl extends Control
{
    public function __construct(
        private readonly CaseFile $case,
        private ?Favorite $favorite,
        private readonly FavoriteRepository $favorites,
    ) {
    }


    public function render(): void
    {
        $this->template->setFile(__DIR__ . '/FavoriteControl.latte');
        $this->template->isFavorite = $this->favorite !== null;
        $this->template->favoriteName = $this->favorite?->name;
        $this->template->render();
    }


    /** Removes the case from the user's favorites (confirmed by the modal). */
    public function handleRemove(): void
    {
        $this->assertLoggedIn();
        if ($this->favorite !== null) {
            $this->favorites->delete($this->favorite);
            $this->favorite = null;
            $this->getPresenter()->flashMessage('Spis byl odebrán z oblíbených.');
        }
        $this->getPresenter()->redirect('this');
    }


    protected function createComponentForm(): Form
    {
        $form = new Form;
        $form->addText('name', 'Vlastní název')
            ->setNullable()
            ->addRule($form::MaxLength, 'Název může mít nejvýše %d znaků.', Favorite::NameMaxLength);
        $form->addSubmit('send', 'Přidat do oblíbených');
        $form->onSuccess[] = $this->formSucceeded(...);
        return $form;
    }


    private function formSucceeded(Form $form, \stdClass $data): void
    {
        $this->assertLoggedIn();
        if ($this->favorite === null) {
            $favorite = new Favorite;
            $favorite->userId = (int) $this->getPresenter()->getUser()->getId();
            $favorite->caseFileId = $this->case->id;
            $favorite->name = $data->name;
            $this->favorites->add($favorite);
            $this->getPresenter()->flashMessage('Spis byl přidán do oblíbených.');
        } else {
            $this->getPresenter()->flashMessage('Spis už ve svých oblíbených máte.');
        }
        $this->getPresenter()->redirect('this');
    }


    /**
     * The star only renders for a logged-in visitor, so reaching a mutation
     * without a session means the request did not come from the UI.
     */
    private function assertLoggedIn(): void
    {
        if (!$this->getPresenter()->getUser()->isLoggedIn()) {
            throw new UserFacingError('Přihlášení je vyžadováno.', IResponse::S403_Forbidden);
        }
    }
}
