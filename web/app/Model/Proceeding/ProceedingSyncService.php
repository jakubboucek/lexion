<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Codelist\Court;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Infosoud\InfosoudOwnershipResolver;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\Spisovka;
use Nette\Database\Explorer;
use Nette\Utils\Json;


/**
 * Realtime refresh of one proceeding from infosoud into the cache. Costs at
 * most 2 upstream requests: the case overview + the first own event (which
 * carries the case subject). Related cases are never fetched here.
 */
final readonly class ProceedingSyncService
{
    public function __construct(
        private InfosoudClient $client,
        private ProceedingRepository $proceedings,
        private InfosoudOwnershipResolver $ownership,
        private ProceedingProjectionService $projection,
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
        $stored = $this->proceedings->getByCase((string) $court->kod, $spisovka);
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
     * @throws InfosoudApiException
     */
    public function refreshFromInfosoud(Court $court, Spisovka $spisovka): ?CaseFile
    {
        $case = $this->client->fetchCase($court, $spisovka);
        if ($case === null) {
            return null;
        }

        // Infosoud matches a pre-2000 case on the last two digits of the year,
        // so asking for 2098 answers with the 1998 case. Trust the echoed
        // `rocnik` over what we asked for and refuse the mismatch rather than
        // caching someone else's case under our year.
        if (isset($case['rocnik']) && CaseYear::fromUpstream((int) $case['rocnik']) !== $spisovka->year) {
            return null;
        }

        // Second request: detail of the first own event (usually ZAHAJ_RIZ with
        // the case subject). Foreign events (appeals, ...) are skipped.
        $first = $this->pickFirstOwnEvent($court, $spisovka, $case['udalosti'] ?? []);
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
            if ($detail !== null) {
                $case['firstEventDetail'] = $detail;
            }
        }

        // The raw JSON write and the projection rebuild must land together:
        // a crash between them would leave a fresh infosoud_at with a stale
        // projection, and no later refresh would notice. HTTP stays outside.
        return $this->explorer->getConnection()->transaction(function () use ($court, $spisovka, $case): ?CaseFile {
            $now = new \DateTimeImmutable;
            $existing = $this->proceedings->getByCase((string) $court->kod, $spisovka);
            if ($existing === null) {
                $stored = new CaseFile;
                $stored->courtKod = (string) $court->kod;
                $stored->registryNorm = $spisovka->registryNorm();
                $stored->senate = $spisovka->senate;
                $stored->bcNumber = $spisovka->number;
                $stored->year = $spisovka->year;
                $stored->infosoudJson = Json::encode($case);
                $stored->infosoudAt = $now;
                $stored = $this->proceedings->insert($stored);
            } else {
                $changes = new CaseFile;
                $changes->infosoudJson = Json::encode($case);
                $changes->infosoudAt = $now;
                $this->proceedings->update($existing->id, $changes);
                $stored = $this->proceedings->getByCase((string) $court->kod, $spisovka);
            }
            if ($stored !== null) {
                // Keep the derived event/relation tables in step with the raw JSON.
                $this->projection->projectInfosoud($stored);
            }
            return $stored;
        });
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
