<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Codelist\Court;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Infosoud\InfosoudRejectedException;
use App\Model\Infosoud\InfosoudOwnershipResolver;
use App\Model\Log\LogService;
use App\Model\Log\LogStatus;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\Spisovka;
use Nette\Database\Explorer;
use Nette\Utils\Json;


/**
 * Realtime refresh of one case file from infosoud into the records. Costs at
 * most 2 upstream requests: the case overview + the first own event (which
 * carries the case subject). Related cases are never fetched here.
 */
final readonly class CaseFileSyncService
{
    public function __construct(
        private InfosoudClient $client,
        private CaseFileRepository $caseFiles,
        private InfosoudOwnershipResolver $ownership,
        private CaseFileProjectionService $projection,
        private CaseFileJournalService $journal,
        private CaseLookupMissRepository $misses,
        private LogService $log,
        private Explorer $explorer,
    ) {
    }


    /**
     * Makes sure the case is on record, going upstream only as far as the
     * policy allows, and reports what happened. The three callers used to
     * spell this out themselves with slightly different wording (tech-debt
     * ST-7); what genuinely differs between them is the policy.
     */
    public function ensureLoaded(Court $court, Spisovka $spisovka, CaseLoadPolicy $policy): CaseLoadResult
    {
        $stored = $this->caseFiles->getByCase((string) $court->kod, $spisovka);
        $enough = match ($policy) {
            CaseLoadPolicy::AnySource => $stored !== null,
            CaseLoadPolicy::InfosoudData => $stored !== null && $stored->infosoudJson !== null,
            CaseLoadPolicy::Refresh => false,
        };
        if ($enough) {
            assert($stored !== null);
            return new CaseLoadResult(CaseLoadOutcome::Known, $stored);
        }

        try {
            $fetched = $this->refreshFromInfosoud($court, $spisovka);
        } catch (InfosoudRejectedException) {
            // Infosoud will not answer for this identity, now or later.
            return new CaseLoadResult(CaseLoadOutcome::Rejected, $stored);
        } catch (InfosoudApiException) {
            return new CaseLoadResult(CaseLoadOutcome::Unavailable, $stored);
        }
        return $fetched !== null
            ? new CaseLoadResult(CaseLoadOutcome::Fetched, $fetched)
            : new CaseLoadResult(CaseLoadOutcome::NotFound, $stored);
    }


    /**
     * Returns the updated cache row, or null when infosoud does not know the
     * case (an existing cache row from other sources is left untouched).
     *
     * With $fetchFirstEventDetail disabled the second request is skipped; the
     * event row keeps the detail it already has, and both the subject column
     * and the PRED_VEC relation are then derived from that stored detail.
     *
     * @throws InfosoudApiException
     */
    public function refreshFromInfosoud(Court $court, Spisovka $spisovka, bool $fetchFirstEventDetail = true): ?CaseFile
    {
        // Deterministic non-answers become miss records - including the ones
        // triggered from the web (a scraped form or an abuse pattern leaves a
        // trail this way). Transient failures are never a miss; they go to the
        // application log so upstream behaviour stays observable.
        try {
            $case = $this->client->fetchCase($court, $spisovka);
        } catch (InfosoudRejectedException $e) {
            $this->misses->record((string) $court->kod, $spisovka, CaseLookupOutcome::Rejected);
            throw $e;
        } catch (InfosoudApiException $e) {
            $this->log->log(
                CaseFileLogKind::InfosoudUnavailable,
                LogStatus::Failed,
                target: $court->kod . ' ' . $spisovka->format(),
                message: $e->getMessage(),
            );
            throw $e;
        }
        if ($case === null) {
            $this->misses->record((string) $court->kod, $spisovka, CaseLookupOutcome::NotFound);
            return null;
        }

        // Infosoud matches a pre-2000 case on the last two digits of the year,
        // so asking for 2098 answers with the 1998 case. Trust the echoed
        // `rocnik` over what we asked for and refuse the mismatch rather than
        // caching someone else's case under our year. The refusal discards a
        // paid-for payload, so it goes into the journal.
        if (isset($case['rocnik']) && CaseYear::fromUpstream((int) $case['rocnik']) !== $spisovka->year) {
            $this->journal->recordCaseResponseRejected(
                $this->caseFiles->getByCase((string) $court->kod, $spisovka),
                $court,
                $spisovka,
                $case,
            );
            $this->misses->record((string) $court->kod, $spisovka, CaseLookupOutcome::YearMismatch);
            return null;
        }

        // Second request: detail of the first own event (usually ZAHAJ_RIZ with
        // the case subject). Foreign events (appeals, ...) are skipped. The
        // response is passed along on the side, never merged into $case: the
        // raw column stays a verbatim snapshot of the overview endpoint.
        $detail = null;
        $first = $fetchFirstEventDetail
            ? $this->pickFirstOwnEvent($court, $spisovka, $case['udalosti'] ?? [])
            : null;
        if ($first !== null) {
            try {
                $detail = $this->client->fetchEventDetail(
                    $court,
                    $spisovka,
                    (string) $first['udalost'],
                    (int) $first['poradi'],
                    (string) ($first['znackaId']['organizace'] ?? $court->kod),
                    ($first['udalostId'] ?? null) !== null ? (string) $first['udalostId'] : null,
                );
            } catch (InfosoudApiException) {
                $detail = null; // the overview alone is still worth caching
            }
        }

        // The raw JSON write and the projection update must land together:
        // a crash between them would leave a fresh infosoud_at with a stale
        // projection, and no later refresh would notice. HTTP stays outside.
        //
        // The projection plan is computed BEFORE the raw JSON write, against
        // the still-untouched state - and when it destroys something, that
        // state is snapshot into the journal first. Capturing later would
        // pair old event rows with an already-rewritten case header: a state
        // that never existed.
        $stored = $this->explorer->getConnection()->transaction(function () use ($court, $spisovka, $case, $detail): ?CaseFile {
            $now = new \DateTimeImmutable;
            $existing = $this->caseFiles->getByCase((string) $court->kod, $spisovka);
            if ($existing === null) {
                $target = new CaseFile;
                $target->courtKod = (string) $court->kod;
                $target->registryNorm = $spisovka->registryNorm();
                $target->senate = $spisovka->senate;
                $target->bcNumber = $spisovka->number;
                $target->year = $spisovka->year;
            } else {
                $target = $existing;
            }
            $plan = $this->projection->plan($target, $case, $detail);
            // A first-seen case plans pure inserts, so a destructive plan
            // implies $existing !== null.
            $before = $existing !== null && $plan->isDestructive()
                ? $this->journal->captureState($existing)
                : null;

            // Case-level summary columns come straight from this payload; the
            // subject is derived from the event rows and written by apply().
            $overview = CaseSummaryExtraction::overviewPatch($case);
            if ($existing === null) {
                $target->status = $overview->status;
                $target->statusDate = $overview->statusDate;
                $target->intakeKind = $overview->intakeKind;
                $target->infosoudJson = Json::encode($case);
                $target->infosoudAt = $now;
                $stored = $this->caseFiles->insert($target);
            } else {
                $changes = $overview;
                $changes->infosoudJson = Json::encode($case);
                $changes->infosoudAt = $now;
                $this->caseFiles->update($existing->id, $changes);
                $stored = $this->caseFiles->getByCase((string) $court->kod, $spisovka);
            }
            if ($stored !== null) {
                // Keep the derived event/relation tables in step with the raw JSON.
                $this->projection->apply($stored, $case, $plan, $detail);
                if ($before !== null) {
                    $this->journal->recordProjectionLoss($stored, $plan, $before, $now);
                }
            }
            return $stored;
        });

        // The identity answered after having been a documented miss: in a
        // running vintage the series simply grew, in a closed one a hole just
        // went public - either way worth a log line, not only the deletion.
        if ($stored !== null && $this->misses->clear((string) $court->kod, $spisovka)) {
            $this->log->log(
                CaseFileLogKind::MissResolved,
                target: $court->kod . ' ' . $spisovka->format(),
            );
        }
        return $stored;
    }


    /**
     * @param array<mixed> $events
     * @return array<mixed>|null
     */
    private function pickFirstOwnEvent(Court $court, Spisovka $spisovka, array $events): ?array
    {
        $own = array_filter($events, function (array $event) use ($court, $spisovka): bool {
            $id = $event['znackaId'] ?? null;
            if (!is_array($id) || ($event['datum'] ?? null) === null) {
                return false;
            }
            return $this->ownership->isOwn(
                $id,
                (string) $court->kod,
                $spisovka->senate,
                $spisovka->registryNorm(),
                $spisovka->number,
                $spisovka->year,
            );
        });
        if ($own === []) {
            return null;
        }
        // Prefer the opening event, else the earliest one.
        foreach ($own as $event) {
            if (($event['udalost'] ?? null) === 'ZAHAJ_RIZ') {
                return $event;
            }
        }
        usort($own, static fn(array $a, array $b) => strcmp((string) $a['datum'], (string) $b['datum']));
        return $own[0];
    }
}
