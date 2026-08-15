<?php declare(strict_types=1);

namespace App\Presentation\Panel\Dashboard;

use App\Model\Codelist\CourtRepository;
use App\Model\Favorite\Favorite;
use App\Model\Favorite\FavoriteGroup;
use App\Model\Favorite\FavoriteGroupRepository;
use App\Model\Favorite\FavoriteRepository;
use App\Model\Proceeding\CaseFile;
use App\Model\Proceeding\CaseSummaryService;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Spisovka\SpisovkaFactory;
use App\Presentation\Accessory\CaseChipFactory;
use App\Presentation\Error\UserFacingError;
use App\Presentation\Panel\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Database\UniqueConstraintViolationException;


/**
 * Favorites overview: the ungrouped bucket first, then the user's groups in
 * manual order. Rows and groups reorder by neighbor-swap signals; removals
 * are confirmed by dialogs in the template. Edit actions own tiny forms.
 */
final class DashboardPresenter extends BasePresenter
{
    private Favorite $favorite;   // set by actionEditFavorite()
    private FavoriteGroup $group; // set by actionEditGroup()


    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly FavoriteGroupRepository $groups,
        private readonly ProceedingRepository $proceedings,
        private readonly CourtRepository $courts,
        private readonly CaseSummaryService $caseSummary,
        private readonly SpisovkaFactory $spisovkaFactory,
        private readonly CaseChipFactory $chips,
    ) {
        parent::__construct();
    }


    public function renderDefault(): void
    {
        $favorites = $this->favorites->findByUser($this->userId());
        // One query for the whole overview instead of a row-by-row lookup.
        $cases = $this->proceedings->findByIds(
            array_map(static fn(Favorite $favorite): int => $favorite->proceedingId, $favorites),
        );

        // Subjects live in the event tables - one query for the whole overview
        // as well, not one per favorite.
        $subjects = $this->caseSummary->subjectsOf(array_values($cases));

        $items = [];
        foreach ($favorites as $favorite) {
            $case = $cases[$favorite->proceedingId] ?? null;
            $items[$favorite->groupId ?? 0][] = $this->favoriteView(
                $favorite,
                $case,
                $case !== null ? $subjects[$case->id] ?? null : null,
            );
        }

        $sections = [];
        if (($items[0] ?? []) !== []) {
            $sections[] = ['group' => null, 'items' => $items[0]];
        }
        foreach ($this->groups->findByUser($this->userId()) as $group) {
            $sections[] = ['group' => $this->groupView($group), 'items' => $items[$group->id] ?? []];
        }
        $this->template->sections = $sections;
    }


    public function actionEditFavorite(int $id): void
    {
        $this->favorite = $this->ownFavorite($id);
        $this['favoriteEditForm']->setDefaults([
            'name' => $this->favorite->name,
            'group' => $this->favorite->groupId ?? 0,
        ]);
    }


    public function renderEditFavorite(): void
    {
        $cases = $this->proceedings->findByIds([$this->favorite->proceedingId]);
        $case = $cases[$this->favorite->proceedingId] ?? null;
        $this->template->favoriteView = $this->favoriteView(
            $this->favorite,
            $case,
            $case !== null ? $this->caseSummary->subjectOf($case) : null,
        );
    }


    public function actionEditGroup(int $id): void
    {
        $this->group = $this->ownGroup($id);
        $this['groupEditForm']->setDefaults(['name' => $this->group->name]);
    }


    public function renderEditGroup(): void
    {
        $this->template->group = $this->groupView($this->group);
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
        $this->groups->remove($this->ownGroup($id));
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
            $group = new FavoriteGroup;
            $group->userId = $this->userId();
            $group->name = $data->name;
            $this->groups->add($group);
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
        $changes = new Favorite;
        $changes->name = $data->name;
        $this->favorites->update($this->favorite->id, $changes);
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
            $changes = new FavoriteGroup;
            $changes->name = $data->name;
            $this->groups->update($this->group->id, $changes);
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
            $choices[$group->id] = $group->name;
        }
        return $choices;
    }


    /**
     * Row view-model for the overview table. The case file is the one the
     * favorite points at; the FK guarantees it exists.
     *
     * @return array<string, mixed>
     */
    private function favoriteView(Favorite $favorite, ?CaseFile $case, ?string $subject): array
    {
        assert($case !== null); // FK guarantees the row
        return [
            'id' => $favorite->id,
            'name' => $favorite->name,
            'subject' => $subject,
            'status' => $this->caseSummary->statusOf($case),
            // Same chip - and the same "when is a file number a link" rule -
            // as everywhere else; the dashboard used to build its own.
            'case' => $this->chips->chip(
                $this->courts->getByKod($case->courtKod),
                $this->spisovkaFactory->fromCaseFile($case),
            ),
        ];
    }


    /** @return array<string, mixed> view-model of a group heading */
    private function groupView(FavoriteGroup $group): array
    {
        return ['id' => $group->id, 'name' => $group->name];
    }


    private function ownFavorite(int $id): Favorite
    {
        $favorite = $this->favorites->getById($id);
        if ($favorite === null || $favorite->userId !== $this->userId()) {
            throw new UserFacingError('Neznámý oblíbený spis.');
        }
        return $favorite;
    }


    private function ownGroup(int $id): FavoriteGroup
    {
        $group = $this->groups->getById($id);
        if ($group === null || $group->userId !== $this->userId()) {
            throw new UserFacingError('Neznámá skupina.');
        }
        return $group;
    }


    private function userId(): int
    {
        return (int) $this->getUser()->getId();
    }
}
