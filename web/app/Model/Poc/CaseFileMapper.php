<?php declare(strict_types=1);

namespace App\Model\Poc;


/**
 * DB row <-> CaseFile mapping via the generic hand-written RowHydrator.
 * The wrapper only pins the entity class and narrows the types; everything
 * else - convention mapping, enum/date handling, partial updates - lives in
 * the reusable hydrator.
 */
final class CaseFileMapper
{
    /** @var RowHydrator<CaseFile> */
    private readonly RowHydrator $hydrator;


    public function __construct()
    {
        $this->hydrator = new RowHydrator(CaseFile::class);
    }


    /** @param array<string, mixed> $row */
    public function fromRow(array $row): CaseFile
    {
        return $this->hydrator->fromRow($row);
    }


    /** @return array<string, mixed> */
    public function toRow(CaseFile $entity): array
    {
        return $this->hydrator->toRow($entity);
    }
}
