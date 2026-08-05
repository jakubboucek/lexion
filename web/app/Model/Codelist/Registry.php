<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Entity;


/**
 * One row of the `registry` codelist (druh věci). A registry code may be kept
 * at several court levels - that is one row per level, so (codeNorm,
 * courtLevel) is the unique key and `courtLevel` NULL means the level is not
 * known (the code was only seen in the infosoud lov).
 *
 * All three registry forms live here: `code` is the display form ("P a Nc"),
 * `codeNorm` the API form ("P A NC") and `slug` the URL form ("panc").
 *
 * One entity 1:1 with the table, descriptive columns included - the codelist
 * is a handful of kilobytes and gets cached whole (see
 * docs/analyza-ciselniky.md), so there is no reason for a slim/full pair.
 *
 * `courtLevel` is typed as an enum on purpose: unlike relation_type.code, the
 * column is guarded by a CHECK constraint, so a value outside the enum cannot
 * exist.
 */
class Registry implements Entity
{
    public int $id;
    public string $code;
    public string $codeNorm;
    public string $slug;
    public ?CourtLevel $courtLevel;
    public ?string $agenda;
    public ?string $description;
    public ?string $note;
}
