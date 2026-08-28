<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Spisovka\Spisovka;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Database\Explorer;
use Nette\Database\Table\Selection;


/**
 * Documented deterministic misses of case lookups (table `case_lookup_miss`).
 * Written by CaseFileSyncService whenever infosoud deterministically does not
 * answer an identity; read by scanning tools to avoid re-asking what is known.
 * A miss says "this was the answer at last_attempt_at" - whether that answer
 * is final is a question for isPermanent(), never a stored flag.
 */
final readonly class CaseLookupMissRepository
{
    private const string Source = 'infosoud';

    /** @var Hydrator<CaseLookupMiss> */
    private Hydrator $hydrator;


    public function __construct(
        private Explorer $db,
        HydratorFactory $hydrators,
    ) {
        $this->hydrator = $hydrators->for(CaseLookupMiss::class);
    }


    public function getByCase(string $courtKod, Spisovka $spisovka): ?CaseLookupMiss
    {
        $row = $this->identity($courtKod, $spisovka)->fetch();
        return $row === null ? null : $this->hydrator->fromData($row);
    }


    /**
     * Records the outcome: first miss inserts, a repeat bumps attempts and
     * last_attempt_at. The outcome overwrites - the latest answer wins.
     */
    public function record(string $courtKod, Spisovka $spisovka, CaseLookupOutcome $outcome): void
    {
        $now = new \DateTimeImmutable;
        $updated = $this->identity($courtKod, $spisovka)->update([
            'outcome' => $outcome->value,
            'attempts+=' => 1,
            'last_attempt_at' => $now,
        ]);
        if ($updated > 0) {
            return;
        }
        $miss = new CaseLookupMiss;
        $miss->courtKod = $courtKod;
        $miss->registryNorm = $spisovka->registryNorm();
        $miss->senate = $spisovka->senate;
        $miss->bcNumber = $spisovka->number;
        $miss->year = $spisovka->year;
        $miss->source = self::Source;
        $miss->outcome = $outcome;
        $miss->attempts = 1;
        $miss->firstAttemptAt = $now;
        $miss->lastAttemptAt = $now;
        $this->db->table('case_lookup_miss')->insert($this->hydrator->toData($miss));
    }


    /** Removes the miss once the identity resolved; reports whether there was one. */
    public function clear(string $courtKod, Spisovka $spisovka): bool
    {
        return $this->identity($courtKod, $spisovka)->delete() > 0;
    }


    /**
     * not_found miss numbers in one series within [from, to] (to = null means
     * open-ended), ascending. The series scanner reads these to skip holes it
     * already documented; below the known series max every such miss is
     * permanent, so the scanner uses this only for the bulk-fill range.
     *
     * @return list<int>
     */
    public function notFoundNumbersInSeries(
        string $courtKod,
        string $registryNorm,
        int $senate,
        int $year,
        int $from,
        ?int $to,
    ): array
    {
        $selection = $this->db->table('case_lookup_miss')
            ->where('source', self::Source)
            ->where('outcome', CaseLookupOutcome::NotFound->value)
            ->where('court_kod', $courtKod)
            ->where('registry_norm', $registryNorm)
            ->where('senate', $senate)
            ->where('year', $year)
            ->where('bc_number >= ?', $from);
        if ($to !== null) {
            $selection->where('bc_number <= ?', $to);
        }
        $numbers = $selection->fetchPairs(null, 'bc_number');
        return array_map(intval(...), array_values($numbers));
    }


    /**
     * Is this miss final - can no future fetch of the identity succeed?
     *
     * A refusal and a year mismatch are deterministic by nature. A not_found
     * is final only once the world has provably moved past the number:
     * either it was verified in a calendar year LATER than the case's vintage
     * (a closed vintage can never grow - and the verification year matters,
     * not today's year: a miss last checked within its own vintage year may
     * have been overtaken by the series since), or - ONLY for a vintage that
     * has already closed - a confirmed case with a higher number exists in the
     * same series (the number was skipped, i.e. a real but unpublished case).
     *
     * The higher-number shortcut is deliberately withheld while the vintage is
     * still the CURRENT year: the series is still growing, so a 404 we recorded
     * before the front reached that number, followed later by the front growing
     * past it, would be misread as a skipped hole - when in fact the number may
     * since have been assigned. During a slow scan of the running year a case
     * can appear "under our hands" exactly this way; a current-year 404 is
     * therefore never final, and gets re-probed until the vintage closes.
     */
    public function isPermanent(CaseLookupMiss $miss): bool
    {
        if ($miss->outcome !== CaseLookupOutcome::NotFound) {
            return true;
        }
        if ($miss->year < (int) $miss->lastAttemptAt->format('Y')) {
            return true;
        }
        if ($miss->year >= (int) new \DateTimeImmutable()->format('Y')) {
            return false; // running (or future) vintage - a 404 can still fill in
        }
        return $this->db->table('case_file')
            ->where('court_kod', $miss->courtKod)
            ->where('registry_norm', $miss->registryNorm)
            ->where('senate', $miss->senate)
            ->where('year', $miss->year)
            ->where('bc_number > ?', $miss->bcNumber)
            ->count('*') > 0;
    }


    private function identity(string $courtKod, Spisovka $spisovka): Selection
    {
        return $this->db->table('case_lookup_miss')
            ->where('source', self::Source)
            ->where('court_kod', $courtKod)
            ->where('registry_norm', $spisovka->registryNorm())
            ->where('senate', $spisovka->senate)
            ->where('bc_number', $spisovka->number)
            ->where('year', $spisovka->year);
    }
}
