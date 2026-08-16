<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Entity;


/**
 * One court of the `court` codelist. `kod` is the infosoud code and the
 * primary key; `slug` is our own URL form (os-pm, ks-hk, ns - see CLAUDE.md).
 *
 * `level` and `region` are typed as enums because the column values are
 * closed sets the database itself holds (level by a CHECK constraint, region
 * by being derived from the court code); `parentKod` stays a plain string
 * code, not an object reference - resolving the parent is the repository's
 * job, and keeping it scalar is what lets the codelist be serialized as a
 * flat graph later (see docs/analyza-ciselniky.md).
 */
class Court implements Entity
{
    public string $kod;
    public string $name;
    public CourtLevel $level;
    public ?string $parentKod;
    public string $slug;
    /** Judicial region of the 1960 division; NULL for the nationwide courts. */
    public ?CourtRegion $region;
}
