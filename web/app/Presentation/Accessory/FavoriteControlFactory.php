<?php declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Model\CaseFile\CaseFile;
use App\Model\Favorite\Favorite;


/**
 * Generated factory of FavoriteControl (nette/di fills the repository in).
 * The caller supplies the case and the bookmark it already read, so the
 * component does not query for a state the page knows.
 */
interface FavoriteControlFactory
{
    public function create(CaseFile $case, ?Favorite $favorite): FavoriteControl;
}
