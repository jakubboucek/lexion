<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Entity;


/**
 * One row of the `court_prefix` codelist: a court prefix as used in
 * ISIR-style file numbers ("KSPH 60 INS ...") mapped to the infosoud court
 * code. The prefix itself is the primary key - the table has no surrogate id.
 */
class CourtPrefix implements Entity
{
    public string $prefix;
    public string $courtKod;
    public ?string $note;
}
