<?php declare(strict_types=1);

namespace App\Model\Poc;


/**
 * Extraction side of the Valinor variant. Valinor's normalizer targets
 * JSON-ish output (stringified dates), while the DB-write direction is
 * nearly identity: rename keys camelCase -> snake_case, unwrap enums and
 * pass date/interval objects through untouched - nette/database formats
 * them itself. Only initialized, non-virtual public properties are
 * extracted, which yields partial-update semantics for free.
 */
final class RowExtractor
{
    /** @return array<string, mixed> */
    public function toRow(object $entity): array
    {
        $row = [];
        $properties = new \ReflectionClass($entity)->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $property) {
            if ($property->isStatic() || $property->isVirtual()) {
                continue;
            }
            if (!$property->isInitialized($entity)) {
                continue; // partial-update semantics
            }
            $value = $property->getValue($entity);
            $row[self::camelToSnake($property->getName())] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        return $row;
    }


    private static function camelToSnake(string $name): string
    {
        return strtolower((string) preg_replace('~[A-Z]~', '_$0', $name));
    }
}
