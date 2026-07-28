<?php declare(strict_types=1);

namespace App\Model\Poc;


/**
 * POC entity of the `proceeding` table row in the target style (see
 * docs/roadmap.md, Typové entity): plain typed public properties, no magic,
 * no constructor; raw source JSON stays untyped (snapshot philosophy);
 * small semantic methods. No deviations forced by the mapper: the entity
 * carries no attributes and the composite output may stay a virtual
 * get-hook property (the hydrator skips virtuals on both directions).
 */
class CaseFile
{
    public int $id;
    public string $courtKod;
    public string $registryNorm;
    public int $senate;
    public int $bcNumber;
    public int $year;
    public ?string $infosoudJson;
    public ?\DateTimeImmutable $infosoudAt;
    public ?string $isirJson;
    public ?\DateTimeImmutable $isirAt;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;

    /** Virtual composite output of several columns - never hydrated nor extracted. */
    public string $identityLabel {
        get => sprintf('%d %s %d/%d @ %s', $this->senate, $this->registryNorm, $this->bcNumber, $this->year, $this->courtKod);
    }


    public function hasInfosoudData(): bool
    {
        return $this->infosoudJson !== null;
    }


    public function hasIsirData(): bool
    {
        return $this->isirJson !== null;
    }
}
