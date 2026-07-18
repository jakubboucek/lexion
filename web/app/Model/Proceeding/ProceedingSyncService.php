<?php declare(strict_types=1);

namespace App\Model\Proceeding;

use App\Model\Codelist\CourtCodeResolver;
use App\Model\Infosoud\InfosoudApiException;
use App\Model\Infosoud\InfosoudClient;
use App\Model\Spisovka\ParsedSpisovka;
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
        private CourtCodeResolver $courtCodes,
    ) {
    }


    /**
     * Returns the updated cache row, or null when infosoud does not know the
     * case (an existing cache row from other sources is left untouched).
     *
     * @throws InfosoudApiException
     */
    public function refreshFromInfosoud(ActiveRow $court, ParsedSpisovka $spisovka): ?ActiveRow
    {
        $case = $this->client->fetchCase(
            $court,
            $spisovka->senate,
            $spisovka->registryNorm(),
            $spisovka->number,
            $spisovka->year,
        );
        if ($case === null) {
            return null;
        }

        // Second request: detail of the first own event (usually ZAHAJ_RIZ with
        // the case subject). Foreign events (appeals, ...) are skipped.
        $first = $this->pickFirstOwnEvent($court, $spisovka, $case['udalosti'] ?? []);
        if ($first !== null) {
            try {
                $detail = $this->client->fetchEventDetail(
                    $court,
                    $spisovka->senate,
                    $spisovka->registryNorm(),
                    $spisovka->number,
                    $spisovka->year,
                    (string) $first['udalost'],
                    (int) $first['poradi'],
                    (string) ($first['znackaId']['organizace'] ?? $court->kod),
                );
            } catch (InfosoudApiException) {
                $detail = null; // the overview alone is still worth caching
            }
            if ($detail !== null) {
                $case['firstEventDetail'] = $detail;
            }
        }

        $now = new \DateTimeImmutable;
        $existing = $this->proceedings->getByCase(
            (string) $court->kod,
            $spisovka->registryNorm(),
            $spisovka->senate,
            $spisovka->number,
            $spisovka->year,
        );
        if ($existing === null) {
            return $this->proceedings->insert([
                'court_kod' => (string) $court->kod,
                'registry_norm' => $spisovka->registryNorm(),
                'senate' => $spisovka->senate,
                'bc_number' => $spisovka->number,
                'year' => $spisovka->year,
                'infosoud_json' => Json::encode($case),
                'infosoud_at' => $now,
            ]);
        }
        $this->proceedings->update((int) $existing->id, [
            'infosoud_json' => Json::encode($case),
            'infosoud_at' => $now,
        ]);
        return $this->proceedings->getByCase(
            (string) $court->kod,
            $spisovka->registryNorm(),
            $spisovka->senate,
            $spisovka->number,
            $spisovka->year,
        );
    }


    /**
     * @param array<mixed> $events
     * @return array<mixed>|null
     */
    private function pickFirstOwnEvent(ActiveRow $court, ParsedSpisovka $spisovka, array $events): ?array
    {
        $own = array_filter($events, function (array $event) use ($court, $spisovka): bool {
            $id = $event['znackaId'] ?? null;
            if (!is_array($id) || ($event['datum'] ?? null) === null) {
                return false;
            }
            // The org code may be an infosoud-internal alias (NS -> NSJIMBM);
            // NS events also carry senate 0 instead of the real senate number.
            $senate = (int) ($id['cisloSenatu'] ?? -1);
            return $this->courtCodes->resolveKod((string) ($id['organizace'] ?? '')) === (string) $court->kod
                && ($senate === $spisovka->senate || $senate === 0)
                && strtoupper((string) ($id['druhVeci'] ?? '')) === $spisovka->registryNorm()
                && (int) ($id['bcVec'] ?? -1) === $spisovka->number
                && (int) ($id['rocnik'] ?? -1) === $spisovka->year;
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
