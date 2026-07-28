<?php declare(strict_types=1);

namespace App\Model\Poc;

use Crell\Serde\Serde;
use Crell\Serde\SerdeCommon;


/**
 * DB row <-> CaseFile mapping via Crell/Serde in both directions.
 *
 * Caveats found by the POC:
 * - Serde refuses source values that are already objects ("Expected value of
 *   type string ... found Nette\Database\DateTime"), so every row needs the
 *   stringify pre-pass below before deserialization;
 * - dates come back from serialize() as formatted STRINGS (per the DateField
 *   attributes on the entity), not as instances - fine for nette inserts,
 *   but the format must be kept DB-compatible on every date property;
 * - Serde does not validate by default: an unmapped column or a missing key
 *   leaves the property silently uninitialized (strictness is per-field
 *   opt-in via requireValue);
 * - TIME columns are a hard stop elsewhere: Serde has no DateInterval
 *   support at all (the Hearing entity from the comparison branch cannot be
 *   mapped without writing a custom importer/exporter).
 */
final class CaseFileMapper
{
    private readonly Serde $serde;


    public function __construct()
    {
        $this->serde = new SerdeCommon();
    }


    /** @param array<string, mixed> $row */
    public function fromRow(array $row): CaseFile
    {
        return $this->serde->deserialize($this->stringifyForSerde($row), from: 'array', to: CaseFile::class);
    }


    /** @return array<string, mixed> */
    public function toRow(CaseFile $entity): array
    {
        $result = $this->serde->serialize($entity, format: 'array');
        assert(is_array($result));
        return $result;
    }


    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stringifyForSerde(array $row): array
    {
        return array_map(static fn(mixed $value): mixed => match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \DateInterval => $value->format('%H:%I:%S'),
            default => $value,
        }, $row);
    }
}
