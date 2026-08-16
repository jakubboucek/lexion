<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\Entity;


/**
 * One rule of the "registry + senate number -> court" mapping. Senate numbers
 * are not nationally unique, so several rules may share (registryNorm, senate)
 * and each contributes one candidate court - a single rule fixes the court,
 * more of them only narrow the set.
 */
class SenateRule implements Entity
{
    public int $id;
    public string $registryNorm;
    public int $senate;
    public string $courtKod;
    public ?string $note;
}
