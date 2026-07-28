<?php declare(strict_types=1);

namespace App\Model\Poc;

use CuyZ\Valinor\Mapper\Source\Source;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;


/**
 * DB row <-> CaseFile mapping via CuyZ/Valinor (hydration) and the thin
 * RowExtractor (extraction).
 *
 * Hydration notes: a plain MapperBuilder suffices - Valinor accepts the
 * already-typed values nette/database produces (Nette\Database\DateTime
 * instances, booleans) as-is, and Source::camelCaseKeys() maps the
 * snake_case columns onto camelCase properties, so the entity itself stays
 * free of any library attributes. Type/null violations raise MappingError
 * with precise, human-readable paths.
 */
final class CaseFileMapper
{
    private readonly TreeMapper $mapper;
    private readonly RowExtractor $extractor;


    public function __construct()
    {
        $this->mapper = new MapperBuilder()->mapper();
        $this->extractor = new RowExtractor();
    }


    /**
     * @param array<string, mixed> $row
     * @throws \CuyZ\Valinor\Mapper\MappingError
     */
    public function fromRow(array $row): CaseFile
    {
        return $this->mapper->map(CaseFile::class, Source::array($row)->camelCaseKeys());
    }


    /** @return array<string, mixed> */
    public function toRow(CaseFile $entity): array
    {
        return $this->extractor->toRow($entity);
    }
}
