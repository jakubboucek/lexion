<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Entity;


/**
 * One row of the `relation_type` codelist: a relation type together with the
 * labels for both reading directions - `label` describes the target seen from
 * the source, `labelReverse` the source seen from the target.
 *
 * The `Entry` suffix is not decoration: the plain name RelationType already
 * belongs to the enum of codes this project projects relations with. The enum
 * is the code side, this entity is the row side.
 *
 * `code` stays a plain string on purpose. The table is an admin-editable
 * codelist, so a row whose code is not (yet) in the enum is a legitimate state
 * - typing the property as RelationType would turn it into a hydration error.
 */
class RelationTypeEntry implements Entity
{
    public string $code;
    public string $label;
    public string $labelReverse;
}
