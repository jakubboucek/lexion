<?php declare(strict_types=1);

namespace App\Model\Sync;

use App\Model\Codelist\CodelistCache;


/**
 * The codelist half of a sync file: the imprint written into every export and
 * the comparison the import runs before it touches any data.
 *
 * Both sides read the codelists from the cached snapshot, i.e. exactly what
 * the application itself uses (see docs/analyza-ciselniky.md) - a codelist
 * migrated without purging the cache is invisible to the app, and so it must
 * be invisible here too, or the gate would pass on data the app cannot read.
 *
 * The comparison is strict: every column of every row must match, and an
 * extra row on either side counts as a difference. Loosening it would need an
 * answer to what a codelist change means for the data hanging off it, and we
 * have none yet (see CodelistMismatchException).
 *
 * Only the codelists the case-file data actually references are compared;
 * `senate_rule` is admin-editable working data and `hearing_room` belongs to
 * the hearings phase.
 */
final readonly class SyncCodelistService
{
    public function __construct(
        private CodelistCache $codelists,
    ) {
    }


    /**
     * All compared codelists as row maps: codelist -> natural key -> columns.
     * Surrogate ids are left out - they are local to one database and would
     * report a difference where there is none.
     *
     * @return array<string, array<string, array<string, string|null>>>
     */
    public function export(): array
    {
        $snapshot = $this->codelists->snapshot();

        $courts = [];
        foreach ($snapshot->courts->ordered as $court) {
            $courts[$court->kod] = [
                'name' => $court->name,
                'level' => $court->level->value,
                'parentKod' => $court->parentKod,
                'slug' => $court->slug,
                'region' => $court->region?->value,
            ];
        }

        // One norm holds one row per court level, so the level is part of the key.
        $registries = [];
        foreach ($snapshot->registries->byNorm as $rows) {
            foreach ($rows as $registry) {
                $registries[$registry->codeNorm . '|' . ($registry->courtLevel->value ?? '')] = [
                    'code' => $registry->code,
                    'codeNorm' => $registry->codeNorm,
                    'slug' => $registry->slug,
                    'courtLevel' => $registry->courtLevel?->value,
                    'agenda' => $registry->agenda,
                    'description' => $registry->description,
                    'note' => $registry->note,
                ];
            }
        }

        $prefixes = [];
        foreach ($snapshot->courtPrefixes->byPrefix as $prefix) {
            $prefixes[$prefix->prefix] = [
                'courtKod' => $prefix->courtKod,
                'note' => $prefix->note,
            ];
        }

        $relationTypes = [];
        foreach ($snapshot->relationTypes->byCode as $entry) {
            $relationTypes[$entry->code] = [
                'label' => $entry->label,
                'labelReverse' => $entry->labelReverse,
            ];
        }

        return [
            'court' => $courts,
            'registry' => $registries,
            'court_prefix' => $prefixes,
            'relation_type' => $relationTypes,
        ];
    }


    /**
     * Differences between the file's codelists and the local ones; an empty
     * list means the import may proceed. A codelist missing from the file
     * altogether reads as "every local row is missing there" - the version
     * gate owns real format drift, this stays a data check.
     *
     * @param array<mixed> $incoming the `codelists` record of the file
     * @return list<CodelistDifference>
     */
    public function compare(array $incoming): array
    {
        $differences = [];
        foreach ($this->export() as $codelist => $localRows) {
            $incomingRows = $incoming[$codelist] ?? null;
            $incomingRows = is_array($incomingRows) ? $incomingRows : [];

            foreach ($incomingRows as $key => $row) {
                $key = (string) $key;
                if (!isset($localRows[$key])) {
                    $differences[] = new CodelistDifference($codelist, $key, CodelistDifferenceKind::MissingLocally);
                } elseif (!self::sameRow($localRows[$key], $row)) {
                    $differences[] = new CodelistDifference($codelist, $key, CodelistDifferenceKind::Differs);
                }
            }

            foreach (array_keys($localRows) as $key) {
                if (!array_key_exists((string) $key, $incomingRows)) {
                    $differences[] = new CodelistDifference($codelist, (string) $key, CodelistDifferenceKind::MissingInFile);
                }
            }
        }
        return $differences;
    }


    /** @param array<string, string|null> $local */
    private static function sameRow(array $local, mixed $incoming): bool
    {
        if (!is_array($incoming) || count($incoming) !== count($local)) {
            return false;
        }
        foreach ($local as $column => $value) {
            // Every exported column is string|null, so a strict comparison is
            // safe against JSON's own typing.
            if (!array_key_exists($column, $incoming) || $incoming[$column] !== $value) {
                return false;
            }
        }
        return true;
    }
}
