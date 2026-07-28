<?php declare(strict_types=1);

namespace App\Model\Poc;


/** Cached per-property metadata of RowHydrator (built once per entity class). */
final readonly class RowHydratorProperty
{
    public function __construct(
        public string $name,
        public string $dbName,
        public string $type,
        public string $kind,      // int|float|string|bool|datetime|interval|enum
        public bool $nullable,
        public bool $writable,
        public bool $readable,
        public \ReflectionProperty $reflection,
    ) {
    }
}
