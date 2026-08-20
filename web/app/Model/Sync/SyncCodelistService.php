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
 * The comparison reports every difference - a missing row on either side, or
 * a single column that differs - but reporting is all it does: differences
 * are warnings, not a veto. Nothing in a codelist row can corrupt imported
 * data. The values that differ drive URLs (`court.slug`, `registry.slug`),
 * display (`registry.code`, `relation_type.label`) and grouping
 * (`court.level`), so the worst a drifted codelist causes is the same case
 * rendering differently in the two environments. What genuinely breaks an
 * import is a *key* the data points at and the receiver does not have, and
 * that is checked per case file while merging, not here (see
 * SyncImportService).
 *
 * The comparison still earns its place as a drift detector: codelists are
 * maintained by hand-applied migrations, decoupled from the deploy, so the
 * format version cannot notice a migration applied on only one side. A sync
 * is the first place that shows up.
 *
 * Only codelists the case-file data references are compared. `court_prefix`
 * is deliberately absent - it maps ISIR prefixes for the file-number parser
 * and no synced row points at it, so comparing it could only produce noise.
 * `senate_rule` is admin-editable working data, and `hearing_room` belongs to
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
            'relation_type' => $relationTypes,
        ];
    }


    /**
     * Differences between the file's codelists and the local ones - a report,
     * not a verdict (see the class docblock). A codelist missing from the
     * file altogether reads as "every local row is missing there"; the
     * version gate owns real format drift, this stays a data check.
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
