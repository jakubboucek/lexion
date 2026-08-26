<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use Nette\Utils\Json;


/**
 * Lazy fetch of one timeline event's upstream detail into its row.
 *
 * The rules used to live in SpisPresenter, with a second copy in
 * bin/infosoud-fetch-hearings.php (tech-debt ST-1 step 2): which case's
 * sequence addresses the record, that upstream "has no detail" is a fact
 * worth storing, and above all the integrity guard - a detail describing
 * another record must never be persisted, because a renumbered upstream
 * would otherwise silently attach it to our row.
 *
 * The caller gets an outcome and decides how to phrase it.
 */
final readonly class EventDetailService
{
    public function __construct(
        private InfosoudClient $client,
        private CaseFileEventRepository $events,
        private CaseFileRepository $caseFiles,
        private CourtRepository $courts,
        private SpisovkaFactory $spisovkaFactory,
        private CaseFileJournalService $journal,
    ) {
    }


    /**
     * Whether the record can be addressed upstream at all: it needs a poradi,
     * and a foreign record also a resolvable owner court. Rows without one are
     * permanent thin records - the UI must not offer fetching or promise the
     * detail will appear later.
     */
    public function isAddressable(CaseFileEvent $event): bool
    {
        return $event->eventOrder !== null
            && ($event->refRegistryNorm === null || $this->ownerCourt($event) !== null);
    }


    /**
     * Fetches the detail unless the row already has one.
     *
     * @param Court $caseCourt court of the case whose timeline holds the event
     * @param Spisovka $caseSpisovka file number of that case
     * @param bool $refetch ask again even for an already fetched row (manual
     *     refresh; the caller owns the cooldown that keeps this rare)
     */
    public function fetch(
        CaseFileEvent $event,
        Court $caseCourt,
        Spisovka $caseSpisovka,
        bool $refetch = false,
    ): EventDetailResult {
        if ($event->detailFetchedAt !== null && !$refetch) {
            return new EventDetailResult(EventDetailOutcome::AlreadyFetched, $event);
        }
        if ($event->eventOrder === null) {
            return new EventDetailResult(EventDetailOutcome::NotAddressable, $event);
        }

        // The case whose sequence owns the record: the case itself, or the
        // foreign case (appeals etc.) from the ref columns.
        if ($event->refRegistryNorm === null) {
            $court = $caseCourt;
            $spisovka = $caseSpisovka;
        } else {
            $court = $this->ownerCourt($event);
            if ($court === null) {
                return new EventDetailResult(EventDetailOutcome::NotAddressable, $event);
            }
            $spisovka = $this->spisovkaFactory->fromEventRef($event);
        }

        try {
            $detail = $this->client->fetchEventDetail(
                $court,
                $spisovka,
                $event->eventCode,
                $event->eventOrder,
                upstreamId: $event->upstreamId,
            );
        } catch (InfosoudApiException) {
            return new EventDetailResult(EventDetailOutcome::Unavailable, $event);
        }

        $now = new \DateTimeImmutable;
        if ($detail === null) {
            // Upstream has no detail for this record; remember that so we do
            // not ask again on every view.
            $missing = CaseSummaryExtraction::hearingPatch(null);
            $missing->detailJson = null;
            $missing->detailFetchedAt = $now;
            return new EventDetailResult(EventDetailOutcome::NoDetail, $this->store($event, $missing));
        }

        if (!$this->describesSameRecord($event, $detail)) {
            // The refused payload is the only authentic evidence of a
            // renumbered case we will ever hold - journal it before it is
            // thrown away with this return.
            $this->journal->recordDetailRejected($event, $detail);
            return new EventDetailResult(EventDetailOutcome::IntegrityBroken, $event);
        }

        // The hearing columns are derived from the very payload being stored,
        // so they travel in the same patch.
        $fetched = CaseSummaryExtraction::hearingPatch($detail);
        $fetched->detailJson = Json::encode($detail);
        $fetched->detailFetchedAt = $now;
        return new EventDetailResult(EventDetailOutcome::Fetched, $this->store($event, $fetched));
    }


    /**
     * Does the fetched detail describe the very record we asked about? A
     * renumbered poradi shows up as a different event type or date, and
     * persisting such a detail would attach another event's content to this
     * row (see docs/analyza-udalosti.md).
     *
     * @param array<mixed> $detail
     */
    private function describesSameRecord(CaseFileEvent $event, array $detail): bool
    {
        $rowDate = $event->eventDate?->format('Y-m-d');
        $detailDate = \DateTimeImmutable::createFromFormat('!d.m.Y', (string) ($detail['datumUdalost'] ?? ''));
        $typeMatches = (string) ($detail['typUdalosti'] ?? '') === $event->eventCode;
        $dateMatches = $rowDate === null
            || ($detailDate !== false && $detailDate->format('Y-m-d') === $rowDate);
        return $typeMatches && $dateMatches;
    }


    /** Owner court of a foreign record, if the codelist knows it. */
    private function ownerCourt(CaseFileEvent $event): ?Court
    {
        return $event->refCourtKod !== null ? $this->courts->getByKod($event->refCourtKod) : null;
    }


    /** Writes the patch and returns the row as it now stands. */
    private function store(CaseFileEvent $event, CaseFileEvent $changes): CaseFileEvent
    {
        $this->events->update($event->id, $changes);
        $stored = $this->events->getById($event->id) ?? $event;
        $this->refreshSubject($stored);
        return $stored;
    }


    /**
     * The case subject is stated by the detail of the case's FIRST OWN event,
     * so a fetch that just filled (or emptied) that very detail has to settle
     * the column - the projection only gets to do it on the next case refresh.
     * A fetch of any other record leaves the subject alone.
     *
     * @throws StoredJsonException stored payload unreadable (data integrity)
     */
    private function refreshSubject(CaseFileEvent $event): void
    {
        $events = $this->events->findByCaseFileAndSource($event->caseFileId, DataSource::Infosoud->value);
        $first = CaseSummaryExtraction::firstOwnDetailed($events);
        if ($first?->id !== $event->id) {
            return;
        }
        $this->caseFiles->update($event->caseFileId, CaseSummaryExtraction::subjectPatch(
            StoredJson::decode($first->detailJson, "event #{$first->id} (detail_json)"),
        ));
    }
}
