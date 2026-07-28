<?php declare(strict_types=1);

namespace App\Model\Poc;


/**
 * POC entity of the `proceeding` table row in the target style (see
 * docs/roadmap.md, Typové entity): plain typed public properties, no magic,
 * no constructor; raw source JSON stays untyped (snapshot philosophy);
 * small semantic methods.
 *
 * Valinor-forced deviation: the identity label would naturally be a virtual
 * get-hook property, but Valinor 2.5 then refuses to map the class ("Value
 * *missing* is not a valid string" for the virtual property), so composite
 * outputs must be methods.
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


    /** Composite output of several columns (a method, not a hook - see class docblock). */
    public function identityLabel(): string
    {
        return sprintf('%d %s %d/%d @ %s', $this->senate, $this->registryNorm, $this->bcNumber, $this->year, $this->courtKod);
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
