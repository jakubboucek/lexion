<?php declare(strict_types=1);

namespace App\Presentation\Panel\Dashboard;

use App\Model\Codelist\CourtRepository;
use App\Model\Favorite\FavoriteGroupRepository;
use App\Model\Favorite\FavoriteRepository;
use App\Model\Proceeding\CaseSummaryService;
use App\Model\Spisovka\SpisovkaFactory;
use App\Presentation\Panel\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;
use Nette\Database\UniqueConstraintViolationException;


/**
 * Favorites overview: the ungrouped bucket first, then the user's groups in
 * manual order. Rows and groups reorder by neighbor-swap signals; removals
 * are confirmed by dialogs in the template. Edit actions own tiny forms.
 */
final class DashboardPresenter extends BasePresenter
{
    private ActiveRow $favorite; // set by actionEditFavorite()
    private ActiveRow $group;    // set by actionEditGroup()


    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly FavoriteGroupRepository $groups,
        private readonly CourtRepository $courts,
        private readonly CaseSummaryService $caseSummary,
        private readonly SpisovkaFactory $spisovkaFactory,
    ) {
        parent::__construct();
    }


    public function renderDefault(): void
    {
        $items = [];
        foreach ($this->favorites->findByUser($this->userId()) as $row) {
            $items[$row->group_id === null ? 0 : (int) $row->group_id][] = $this->favoriteView($row);
        }

        $sections = [];
        if (($items[0] ?? []) !== []) {
            $sections[] = ['group' => null, 'items' => $items[0]];
        }
        foreach ($this->groups->findByUser($this->userId()) as $group) {
            $sections[] = ['group' => $group, 'items' => $items[(int) $group->id] ?? []];
        }
        $this->template->sections = $sections;
    }


    public function actionEditFavorite(int $id): void
    {
        $this->favorite = $this->ownFavorite($id);
        $this['favoriteEditForm']->setDefaults([
            'name' => $this->favorite->name,
            'group' => $this->favorite->group_id === null ? 0 : (int) $this->favorite->group_id,
        ]);
    }


    public function renderEditFavorite(): void
    {
        $this->template->favoriteView = $this->favoriteView($this->favorite);
    }


    public function actionEditGroup(int $id): void
    {
        $this->group = $this->ownGroup($id);
        $this['groupEditForm']->setDefaults(['name' => $this->group->name]);
    }


    public function renderEditGroup(): void
    {
        $this->template->group = $this->group;
    }


    /** Swaps the favorite with its neighbor within the bucket. */
    public function handleMoveFavorite(int $id, string $direction): void
    {
        $this->favorites->move($this->ownFavorite($id), $direction === 'up' ? -1 : 1);
        $this->redirect('this');
    }


    /** Swaps the group with its neighbor in the group list. */
    public function handleMoveGroup(int $id, string $direction): void
    {
        $this->groups->move($this->ownGroup($id), $direction === 'up' ? -1 : 1);
        $this->redirect('this');
    }


    /** Removes a favorite (confirmed by a dialog in the template). */
    public function handleRemoveFavorite(int $id): void
    {
        $this->favorites->delete($this->ownFavorite($id));
        $this->flashMessage('Spis byl odebrán z oblíbených.');
        $this->redirect('this');
    }


    /** Removes a group; its favorites move to the end of the general list. */
    public function handleRemoveGroup(int $id): void
    {
        $group = $this->ownGroup($id);
        $this->favorites->ungroupAll($this->userId(), (int) $group->id);
        $this->groups->delete($group);
        $this->flashMessage('Skupina byla zrušena, její spisy zůstávají v obecném seznamu.');
        $this->redirect('this');
    }


    protected function createComponentNewGroupForm(): Form
    {
        $form = new Form;
        $form->addText('name', 'Název skupiny')
            ->setRequired('Zadejte název skupiny.')
            ->addRule($form::MaxLength, 'Název může mít nejvýše %d znaků.', 100);
        $form->addSubmit('send', 'Založit skupinu');
        $form->onSuccess[] = $this->newGroupFormSucceeded(...);
        return $form;
    }


    private function newGroupFormSucceeded(Form $form, \stdClass $data): void
    {
        try {
            $this->groups->add($this->userId(), $data->name);
        } catch (UniqueConstraintViolationException) {
            $form['name']->addError('Skupinu s tímto názvem už máte.');
            return;
        }
        $this->flashMessage('Skupina byla založena.');
        $this->redirect('this');
    }


    protected function createComponentFavoriteEditForm(): Form
    {
        $form = new Form;
        $form->addText('name', 'Vlastní název')
            ->setNullable()
            ->addRule($form::MaxLength, 'Název může mít nejvýše %d znaků.', 255);
        $form->addSelect('group', 'Skupina', $this->groupChoices());
        $form->addSubmit('send', 'Uložit');
        $form->onSuccess[] = $this->favoriteEditFormSucceeded(...);
        return $form;
    }


    private function favoriteEditFormSucceeded(Form $form, \stdClass $data): void
    {
        $this->favorites->update((int) $this->favorite->id, ['name' => $data->name]);
        $this->favorites->moveToGroup($this->favorite, $data->group === 0 ? null : (int) $data->group);
        $this->flashMessage('Oblíbený spis byl upraven.');
        $this->redirect('default');
    }


    protected function createComponentGroupEditForm(): Form
    {
        $form = new Form;
        $form->addText('name', 'Název skupiny')
            ->setRequired('Zadejte název skupiny.')
            ->addRule($form::MaxLength, 'Název může mít nejvýše %d znaků.', 100);
        $form->addSubmit('send', 'Uložit');
        $form->onSuccess[] = $this->groupEditFormSucceeded(...);
        return $form;
    }


    private function groupEditFormSucceeded(Form $form, \stdClass $data): void
    {
        try {
            $this->groups->update((int) $this->group->id, ['name' => $data->name]);
        } catch (UniqueConstraintViolationException) {
            $form['name']->addError('Skupinu s tímto názvem už máte.');
            return;
        }
        $this->flashMessage('Skupina byla přejmenována.');
        $this->redirect('default');
    }


    /** Select choices: 0 = the general (ungrouped) list, then real groups. */
    private function groupChoices(): array
    {
        $choices = [0 => '— obecný seznam —'];
        foreach ($this->groups->findByUser($this->userId()) as $group) {
            $choices[(int) $group->id] = (string) $group->name;
        }
        return $choices;
    }


    /** @return array<string, mixed> row view-model for the overview table */
    private function favoriteView(ActiveRow $favorite): array
    {
        $proceeding = $favorite->ref('proceeding');
        assert($proceeding instanceof ActiveRow); // FK guarantees the row
        $court = $this->courts->getByKod((string) $proceeding->court_kod);
        $spisovka = $this->spisovkaFactory->fromProceeding($proceeding);
        return [
            'id' => (int) $favorite->id,
            'name' => $favorite->name !== null ? (string) $favorite->name : null,
            'label' => $spisovka->format(),
            'courtSlug' => $court !== null ? (string) $court->slug : null,
            'courtName' => $court?->name,
            'slug' => $spisovka->toSlug(),
            'subject' => $this->caseSummary->subjectOf($proceeding),
            'status' => $this->caseSummary->statusOf($proceeding),
        ];
    }


    private function ownFavorite(int $id): ActiveRow
    {
        $favorite = $this->favorites->getById($id);
        if ($favorite === null || (int) $favorite->user_id !== $this->userId()) {
            $this->error('Neznámý oblíbený spis.');
        }
        return $favorite;
    }


    private function ownGroup(int $id): ActiveRow
    {
        $group = $this->groups->getById($id);
        if ($group === null || (int) $group->user_id !== $this->userId()) {
            $this->error('Neznámá skupina.');
        }
        return $group;
    }


    private function userId(): int
    {
        return (int) $this->getUser()->getId();
    }
}
