<?php declare(strict_types=1);

namespace App\Model\Poc;

use Crell\Serde\Attributes\ClassSettings;
use Crell\Serde\Attributes\DateField;
use Crell\Serde\Renaming\Cases;


/**
 * POC entity of the `proceeding` table row in the target style (see
 * docs/roadmap.md, Typové entity): plain typed public properties, no magic,
 * no constructor; raw source JSON stays untyped (snapshot philosophy);
 * small semantic methods.
 *
 * Serde-forced deviations: the entity carries library attributes - the
 * class-level rename strategy for camelCase <-> snake_case and a DateField
 * on every date property so exported dates come out in a DB-friendly format
 * instead of RFC3339. (The virtual get-hook property below is fine: Serde
 * skips it.)
 */
#[ClassSettings(renameWith: Cases::snake_case)]
class CaseFile
{
    public int $id;
    public string $courtKod;
    public string $registryNorm;
    public int $senate;
    public int $bcNumber;
    public int $year;
    public ?string $infosoudJson;
    #[DateField(format: 'Y-m-d H:i:s')]
    public ?\DateTimeImmutable $infosoudAt;
    public ?string $isirJson;
    #[DateField(format: 'Y-m-d H:i:s')]
    public ?\DateTimeImmutable $isirAt;
    #[DateField(format: 'Y-m-d H:i:s')]
    public \DateTimeImmutable $createdAt;
    #[DateField(format: 'Y-m-d H:i:s')]
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
