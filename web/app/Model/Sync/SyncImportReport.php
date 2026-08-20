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

    /** Where the file came from (host of the exporting environment). */
    public ?string $origin = null;
    public ?\DateTimeImmutable $generatedAt = null;
    public int $part = 1;
    public int $parts = 1;

    /**
     * Skipped case files, capped for display - the full count stays in
     * $caseFilesSkipped and every problem reaches the application log.
     *
     * @var list<SyncProblem>
     */
    public array $problems = [];

    private const int ProblemsShown = 200;


    public function addProblem(SyncProblem $problem): void
    {
        $this->caseFilesSkipped++;
        if (count($this->problems) < self::ProblemsShown) {
            $this->problems[] = $problem;
        }
    }


    /** Problems omitted from the list above. */
    public function problemsOmitted(): int
    {
        return $this->caseFilesSkipped - count($this->problems);
    }


    public function caseFilesTotal(): int
    {
        return $this->caseFilesCreated + $this->caseFilesUpdated
            + $this->caseFilesUnchanged + $this->caseFilesSkipped;
    }
}
