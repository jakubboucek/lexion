<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * One decoded record of a sync file, read through typed accessors.
 *
 * A record arrives as untyped JSON, and every domain reading one needs the
 * same handful of questions answered the same way: is this string there, is
 * that an integer, is this a valid moment. Spelling that out per field per
 * domain is where a reader silently starts accepting `null` as `0`. Each
 * accessor either returns the demanded type or throws, so a malformed record
 * fails at the field that is wrong and names it.
 */
final readonly class SyncRecord
{
    /** @param array<mixed> $data */
    public function __construct(
        private array $data,
    ) {
    }


    public function type(): ?RecordType
    {
        $type = $this->data['type'] ?? null;
        return RecordType::tryFrom(is_string($type) ? $type : '');
    }


    /** The raw value, for the rare caller that does its own interpretation. */
    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }


    /** A nested object, e.g. the `case` of a case file record. */
    public function child(string $key): self
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value)) {
            throw new SyncException("Záznam neobsahuje část „{$key}“.");
        }
        return new self($value);
    }


    public function optionalChild(string $key): ?self
    {
        return ($this->data[$key] ?? null) === null ? null : $this->child($key);
    }


    /**
     * A nested list of objects, e.g. the events of a case file. A missing key
     * reads as an empty list - a record with nothing to nest need not say so.
     *
     * @return list<self>
     */
    public function children(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new SyncException("Seznam „{$key}“ je poškozený.");
        }
        $children = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new SyncException("Položka seznamu „{$key}“ není záznamem.");
            }
            $children[] = new self($item);
        }
        return $children;
    }


    public function text(string $key): string
    {
        $value = $this->optionalText($key);
        if ($value === null || $value === '') {
            throw new SyncException("Chybí povinná hodnota „{$key}“.");
        }
        return $value;
    }


    public function optionalText(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new SyncException("Hodnota „{$key}“ není text.");
        }
        return $value;
    }


    public function number(string $key): int
    {
        $value = $this->optionalNumber($key);
        if ($value === null) {
            throw new SyncException("Chybí povinné číslo „{$key}“.");
        }
        return $value;
    }


    public function optionalNumber(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new SyncException("Hodnota „{$key}“ není celé číslo.");
        }
        return $value;
    }


    public function flag(string $key): bool
    {
        $value = $this->data[$key] ?? null;
        if (!is_bool($value)) {
            throw new SyncException("Hodnota „{$key}“ není ano/ne.");
        }
        return $value;
    }


    public function moment(string $key): \DateTimeImmutable
    {
        $value = $this->optionalMoment($key);
        if ($value === null) {
            throw new SyncException("Chybí povinný čas „{$key}“.");
        }
        return $value;
    }


    public function optionalMoment(string $key): ?\DateTimeImmutable
    {
        $value = $this->optionalText($key);
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new SyncException("Hodnota „{$key}“ není platný čas.", previous: $e);
        }
    }


    /**
     * A backed enum value. The set of cases is ours on both sides, so an
     * unknown one is a malformed record, not a difference to tolerate.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    public function enum(string $key, string $enum): \BackedEnum
    {
        $case = $enum::tryFrom($this->text($key));
        if ($case === null) {
            throw new SyncException("Hodnota „{$key}“ není známá hodnota.");
        }
        return $case;
    }
}
