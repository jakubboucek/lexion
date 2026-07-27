<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Infosoud\InfosoudOwnershipResolver;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\Spisovka;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
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
     * Returns the updated cache row, or null when infosoud does not know the
     * case (an existing cache row from other sources is left untouched).
     *
     * @throws InfosoudApiException
     */
    public function refreshFromInfosoud(ActiveRow $court, Spisovka $spisovka): ?ActiveRow
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
        return $this->explorer->getConnection()->transaction(function () use ($court, $spisovka, $case): ?ActiveRow {
            $now = new \DateTimeImmutable;
            $existing = $this->proceedings->getByCase((string) $court->kod, $spisovka);
            if ($existing === null) {
                $row = $this->proceedings->insert([
                    'court_kod' => (string) $court->kod,
                    'registry_norm' => $spisovka->registryNorm(),
                    'senate' => $spisovka->senate,
                    'bc_number' => $spisovka->number,
                    'year' => $spisovka->year,
                    'infosoud_json' => Json::encode($case),
                    'infosoud_at' => $now,
                ]);
            } else {
                $this->proceedings->update((int) $existing->id, [
                    'infosoud_json' => Json::encode($case),
                    'infosoud_at' => $now,
                ]);
                $row = $this->proceedings->getByCase((string) $court->kod, $spisovka);
            }
            if ($row !== null) {
                // Keep the derived event/relation tables in step with the raw JSON.
                $this->projection->projectInfosoud($row);
            }
            return $row;
        });
    }


    /**
     * @param array<mixed> $events
     * @return array<mixed>|null
     */
    private function pickFirstOwnEvent(ActiveRow $court, Spisovka $spisovka, array $events): ?array
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
