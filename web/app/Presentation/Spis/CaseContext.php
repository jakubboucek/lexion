<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\CaseFile\CaseFile;
use App\Model\Codelist\Court;
use App\Model\Spisovka\Spisovka;


/**
 * The case a page is about: the stored row, its court and the canonical file
 * number (rebuilt from the row, so its display form comes from the codelist).
 * The three always travel together - CaseViewFactory takes them as one value
 * instead of repeating them in every signature.
 */
final readonly class CaseContext
{
    public function __construct(
        public CaseFile $case,
        public Court $court,
        public Spisovka $spisovka,
    ) {
    }
}
