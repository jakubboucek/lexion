<?php declare(strict_types=1);

namespace App\Model\Sync;


/**
 * What one import run did. Counts are the operator's only feedback that the
 * file landed where it should, so they distinguish "nothing to do" (the file
 * was already applied - the merge is idempotent, so this is the normal result
 * of a repeated run) from "nothing happened" (a mistake).
 */
final class SyncImportReport
{
    public int $caseFilesCreated = 0;
    public int $caseFilesUpdated = 0;
    public int $caseFilesUnchanged = 0;
    public int $caseFilesSkipped = 0;
    public int $eventsCreated = 0;
    public int $eventsUpdated = 0;
    public int $relationsCreated = 0;

    public int $hearingsCreated = 0;
    public int $hearingsUpdated = 0;
    public int $hearingsUnchanged = 0;
    public int $hearingsSkipped = 0;
    public int $observationsCreated = 0;
    public int $roomsCreated = 0;
    public int $roomsUpdated = 0;
    public int $roomsUnchanged = 0;
    public int $roomsSkipped = 0;

    /** Where the file came from (host of the exporting environment). */
    public ?string $origin = null;
    public ?\DateTimeImmutable $generatedAt = null;
    public ?SyncDataset $dataset = null;
    public int $part = 1;
    public int $parts = 1;

    /**
     * Skipped records, capped for display - the full count stays in
     * $problemsTotal and every problem reaches the application log.
     *
     * @var list<SyncProblem>
     */
    public array $problems = [];

    public int $problemsTotal = 0;

    /**
     * Codelist rows that differ between the two environments. Warnings, not
     * failures: no codelist column can corrupt imported data, only a key the
     * data points at can, and that is caught per record (see
     * SyncCodelistService).
     *
     * @var list<CodelistDifference>
     */
    public array $codelistDifferences = [];

    private const int ProblemsShown = 200;


    /**
     * Records a skipped record. The per-domain skip counter stays with the
     * caller - which kind of thing was skipped is the domain's business, and
     * a single counter here would have to guess.
     */
    public function addProblem(SyncProblem $problem): void
    {
        $this->problemsTotal++;
        if (count($this->problems) < self::ProblemsShown) {
            $this->problems[] = $problem;
        }
    }


    /** Problems omitted from the list above. */
    public function problemsOmitted(): int
    {
        return $this->problemsTotal - count($this->problems);
    }


    public function caseFilesTotal(): int
    {
        return $this->caseFilesCreated + $this->caseFilesUpdated
            + $this->caseFilesUnchanged + $this->caseFilesSkipped;
    }


    public function hearingsTotal(): int
    {
        return $this->hearingsCreated + $this->hearingsUpdated
            + $this->hearingsUnchanged + $this->hearingsSkipped;
    }


    public function roomsTotal(): int
    {
        return $this->roomsCreated + $this->roomsUpdated
            + $this->roomsUnchanged + $this->roomsSkipped;
    }


    /**
     * Summary payload for the run log, so the report outlives the HTTP
     * response. Individual problems live in the run's problems file; codelist
     * differences shrink to a per-codelist count here.
     *
     * @return array<string, mixed>
     */
    public function toLogData(): array
    {
        $codelists = [];
        foreach ($this->codelistDifferences as $difference) {
            $codelists[$difference->codelist] = ($codelists[$difference->codelist] ?? 0) + 1;
        }
        return [
            'dataset' => $this->dataset?->value,
            'origin' => $this->origin,
            'generatedAt' => $this->generatedAt?->format('Y-m-d H:i:s'),
            'part' => $this->part,
            'parts' => $this->parts,
            'caseFiles' => [
                'created' => $this->caseFilesCreated,
                'updated' => $this->caseFilesUpdated,
                'unchanged' => $this->caseFilesUnchanged,
                'skipped' => $this->caseFilesSkipped,
                'eventsCreated' => $this->eventsCreated,
                'eventsUpdated' => $this->eventsUpdated,
                'relationsCreated' => $this->relationsCreated,
            ],
            'hearings' => [
                'created' => $this->hearingsCreated,
                'updated' => $this->hearingsUpdated,
                'unchanged' => $this->hearingsUnchanged,
                'skipped' => $this->hearingsSkipped,
                'observationsCreated' => $this->observationsCreated,
            ],
            'rooms' => [
                'created' => $this->roomsCreated,
                'updated' => $this->roomsUpdated,
                'unchanged' => $this->roomsUnchanged,
                'skipped' => $this->roomsSkipped,
            ],
            'problemsTotal' => $this->problemsTotal,
            'codelistDifferences' => $codelists,
        ];
    }
}
