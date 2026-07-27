<?php declare(strict_types=1);

namespace App\Model\Infosoud;

use App\Model\Codelist\CourtLevel;
use App\Model\Spisovka\CaseYear;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * Thin client of the unofficial infosoud JSON API (see docs/infosoud-api.md).
 * The only place that knows the wire format and its quirks ("not found" comes
 * back as HTTP 400 with a RIZENI_0000 message code).
 */
final class InfosoudClient
{
    private const string SearchUrl = 'https://infosoud.gov.cz/api/v1/rizeni/vyhledej';
    private const string EventUrl = 'https://infosoud.gov.cz/api/v1/udalost/vyhledej';
    private const int TimeoutSeconds = 20;


    /**
     * Fetches a case; returns the decoded response or null when the case does
     * not exist. NSS is not covered by infosoud at all.
     *
     * @return array<mixed>|null
     * @throws InfosoudApiException
     */
    public function fetchCase(ActiveRow $court, int $senate, string $registryNorm, int $bcNumber, int $year): ?array
    {
        $level = CourtLevel::from($court->level);
        $payload = match ($level) {
            CourtLevel::District => [
                'typOrganizace' => 'VSECHNY_KRAJE',
                'okresniSoud' => (string) $court->kod,
            ],
            CourtLevel::Regional, CourtLevel::High => [
                'typOrganizace' => 'VSECHNY_KRAJE',
                'druhOrganizace' => (string) $court->kod,
            ],
            CourtLevel::Supreme => [
                'typOrganizace' => 'NEJVYSSI',
            ],
            CourtLevel::SupremeAdministrative => throw new InfosoudApiException('Infosoud does not cover NSS proceedings.'),
        };
        $payload += [
            'cisloSenatu' => (string) $senate,
            'druhVeci' => strtoupper($registryNorm),
            'bcVec' => (string) $bcNumber,
            'rocnik' => (string) CaseYear::forApi($year),
        ];

        [$status, $body] = $this->post(self::SearchUrl, $payload);

        try {
            $decoded = Json::decode($body, forceArrays: true);
        } catch (JsonException $e) {
            throw new InfosoudApiException("Infosoud returned invalid JSON (HTTP $status).", previous: $e);
        }
        if (!is_array($decoded)) {
            throw new InfosoudApiException("Infosoud returned unexpected payload (HTTP $status).");
        }

        if ($status === 400 && str_starts_with((string) ($decoded['message'] ?? ''), 'RIZENI_0000')) {
            return null; // quirk: "case not found" is reported as HTTP 400
        }
        if ($status !== 200) {
            throw new InfosoudApiException(
                sprintf('Infosoud request failed (HTTP %d): %s', $status, (string) ($decoded['message'] ?? $body)),
            );
        }

        return $decoded;
    }


    /**
     * Fetches the detail of one event (attributes incl. the case subject).
     * Returns null when infosoud does not know the event.
     *
     * @return array<mixed>|null
     * @throws InfosoudApiException
     */
    public function fetchEventDetail(
        ActiveRow $court,
        int $senate,
        string $registryNorm,
        int $bcNumber,
        int $year,
        string $eventCode,
        int $eventOrder,
        ?string $organizaceId = null,
        ?string $upstreamId = null,
    ): ?array
    {
        $level = CourtLevel::from($court->level);
        // organizaceId mirrors udalosti[].znackaId.organizace, which equals the
        // court kod everywhere except the NS internal alias.
        $organizaceId ??= $level === CourtLevel::Supreme ? 'NSJIMBM' : (string) $court->kod;
        $payload = match ($level) {
            CourtLevel::District => ['typOrganizace' => 'VSECHNY_KRAJE', 'okresniSoud' => (string) $court->kod],
            CourtLevel::Regional, CourtLevel::High => ['typOrganizace' => 'VSECHNY_KRAJE', 'druhOrganizace' => (string) $court->kod],
            CourtLevel::Supreme => ['typOrganizace' => 'NEJVYSSI'],
            CourtLevel::SupremeAdministrative => throw new InfosoudApiException('Infosoud does not cover NSS proceedings.'),
        };
        $payload += [
            'cisloSenatu' => (string) $senate,
            'druhVeci' => strtoupper($registryNorm),
            'bcVec' => (string) $bcNumber,
            'rocnik' => (string) CaseYear::forApi($year),
            'druhUdalosti' => $eventCode,
            'poradiUdalosti' => (string) $eventOrder,
            'organizaceId' => $organizaceId,
        ];
        if ($upstreamId !== null) {
            // CEPR-backed cases (EPR registry) refuse the lookup with
            // UDALOST_0001 unless udalostId is sent along; ISAS courts have
            // it null in the timeline and resolve by (druh, poradi) alone.
            $payload['udalostId'] = $upstreamId;
        }

        [$status, $body] = $this->post(self::EventUrl, $payload);

        try {
            $decoded = Json::decode($body, forceArrays: true);
        } catch (JsonException $e) {
            throw new InfosoudApiException("Infosoud returned invalid JSON (HTTP $status).", previous: $e);
        }
        if (!is_array($decoded)) {
            throw new InfosoudApiException("Infosoud returned unexpected payload (HTTP $status).");
        }
        if ($status === 400 && str_starts_with((string) ($decoded['message'] ?? ''), 'UDALOST_0000')) {
            // Only the genuine "event not found" may be treated (and cached) as
            // "upstream has no detail". Any other 400 - UDALOST_0001, validation
            // codes, a changed contract - is a request failure and must throw,
            // otherwise our own mistake gets persisted as a permanent no-detail.
            return null;
        }
        if ($status !== 200) {
            throw new InfosoudApiException(
                sprintf('Infosoud event request failed (HTTP %d): %s', $status, (string) ($decoded['message'] ?? $body)),
            );
        }
        return $decoded;
    }


    /**
     * @param array<string, string> $payload
     * @return array{int, string}
     */
    private function post(string $url, array $payload): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => Json::encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TimeoutSeconds,
        ]);
        $body = curl_exec($handle);
        if ($body === false) {
            throw new InfosoudApiException('Infosoud request failed: ' . curl_error($handle));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        return [$status, (string) $body];
    }
}
