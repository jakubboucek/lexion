<?php declare(strict_types=1);

namespace App\Model\Poc;


/**
 * POC: a basic-construction port of the hand-written Hydrator from the
 * skradbuza.cz project (the priority reference named in docs/roadmap.md),
 * trimmed for this comparison: no EntityStruct/JSON structs and timezone
 * pass-through instead of the DateTimeModel injection.
 *
 * One instance serves one entity class. Property metadata - the db column
 * name, the resolved value kind and the ReflectionProperty - is reflected
 * once and cached, so per-row work is a plain loop with a match. Values
 * already typed by nette/database (DateTimeImmutable, bool, DateInterval)
 * pass through untouched; enums map by backed value; date/interval objects
 * are handed back to the DB as instances on extraction. Virtual hook
 * properties are skipped in both directions, and only initialized
 * properties are extracted (partial-update semantics).
 *
 * @template T of object
 */
final class RowHydrator
{
    /** @var array<string, RowHydratorProperty> */
    private array $cache;


    /** @param class-string<T> $entityClass */
    public function __construct(
        private readonly string $entityClass,
    ) {
    }


    /**
     * @param array<string, mixed> $row
     * @return T
     */
    public function fromRow(array $row): object
    {
        $entity = new $this->entityClass();

        foreach ($this->properties() as $name => $meta) {
            if (!$meta->writable) {
                continue;
            }
            if (!array_key_exists($meta->dbName, $row)) {
                throw new \InvalidArgumentException(
                    "Missing database field '{$meta->dbName}' for property {$this->entityClass}::\${$name}.",
                );
            }
            $value = $row[$meta->dbName];

            if ($value === null) {
                if (!$meta->nullable) {
                    throw new \InvalidArgumentException(
                        "Non-nullable property {$this->entityClass}::\${$name} cannot be null.",
                    );
                }
                $entity->$name = null;
                continue;
            }

            $entity->$name = match ($meta->kind) {
                'int' => (int) $value,
                'float' => (float) $value,
                'string' => (string) $value,
                'bool' => (bool) $value,
                'datetime' => $this->asDateTimeImmutable($name, $value),
                'interval' => $value instanceof \DateInterval
                    ? $value
                    : throw new \LogicException("Expected DateInterval for {$this->entityClass}::\${$name}."),
                'enum' => $meta->type::from($value),
                default => throw new \InvalidArgumentException(
                    "Unsupported property type '{$meta->type}' for {$this->entityClass}::\${$name}.",
                ),
            };
        }

        return $entity;
    }


    /**
     * Only initialized, readable properties are extracted (partial-update
     * semantics); date/interval objects pass through untouched - the database
     * layer formats them itself.
     *
     * @return array<string, mixed>
     */
    public function toRow(object $entity): array
    {
        $row = [];
        foreach ($this->properties() as $meta) {
            if (!$meta->readable || !$meta->reflection->isInitialized($entity)) {
                continue;
            }
            $value = $entity->{$meta->name};
            $row[$meta->dbName] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        return $row;
    }


    /** @return array<string, RowHydratorProperty> */
    private function properties(): array
    {
        if (isset($this->cache)) {
            return $this->cache;
        }

        $vars = [];
        $properties = new \ReflectionClass($this->entityClass)->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $name = $property->getName();
            $type = $property->getType();
            if ($type !== null && !$type instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException(
                    "Only simple types are supported for property {$this->entityClass}::\${$name}.",
                );
            }
            $typeName = $type?->getName() ?? 'mixed';
            $kind = ($type === null || $type->isBuiltin()) ? $typeName : match (true) {
                is_subclass_of($typeName, \DateTimeInterface::class) => 'datetime',
                $typeName === \DateInterval::class => 'interval',
                is_subclass_of($typeName, \BackedEnum::class) => 'enum',
                default => $typeName,
            };

            $writable = !$property->isProtectedSet()
                && !$property->isPrivateSet()
                && (!$property->isVirtual() || $property->hasHook(\PropertyHookType::Set));
            $readable = !$property->isVirtual();

            $vars[$name] = new RowHydratorProperty(
                name: $name,
                dbName: strtolower((string) preg_replace('~[A-Z]~', '_$0', $name)),
                type: $typeName,
                kind: $kind,
                nullable: $type?->allowsNull() ?? true,
                writable: $writable,
                readable: $readable,
                reflection: $property,
            );
        }

        return $this->cache = $vars;
    }


    private function asDateTimeImmutable(string $name, mixed $value): \DateTimeImmutable
    {
        return match (true) {
            $value instanceof \DateTimeImmutable => $value,
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value),
            default => throw new \LogicException(
                "Cannot hydrate {$this->entityClass}::\${$name}: expected DateTimeInterface, got "
                . get_debug_type($value) . '.',
            ),
        };
    }
}
