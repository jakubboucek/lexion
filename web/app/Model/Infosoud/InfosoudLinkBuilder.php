<?php declare(strict_types=1);

namespace App\Model\Infosoud;

use App\Model\Codelist\Court;
use App\Model\Codelist\CourtLevel;
use App\Model\Spisovka\CaseYear;
use App\Model\Spisovka\Spisovka;


/**
 * Builds public deep-links into the infosoud SPA (see docs/infosoud-api.md).
 */
final class InfosoudLinkBuilder
{
    private const string BaseUrl = 'https://infosoud.gov.cz/InfoSoud/detail-rizeni';
    private const string EventBaseUrl = 'https://infosoud.gov.cz/InfoSoud/detail-udalosti';


    /** Returns null when the court is not covered by infosoud (NSS). */
    public function detailUrl(Spisovka $spisovka, Court $court): ?string
    {
        $params = $this->caseParams($spisovka, $court);
        return $params !== null ? self::BaseUrl . '?' . http_build_query($params) : null;
    }


    /**
     * Deep-link to the SPA event detail. The SPA resolver forwards the query
     * parameters 1:1 to the API, so the URL mirrors the udalost/vyhledej
     * payload; $owner identifies the case whose sequence owns the record when
     * it differs from the case itself (foreign events - appeals etc.).
     *
     * $upstreamId is the udalostId: CEPR-backed cases (EPR) do not resolve by
     * (druh, poradi) alone and the SPA silently falls back to the search form
     * without it, so it must be passed on exactly like fetchEventDetail() does.
     * ISAS courts carry it null and are unaffected.
     */
    public function eventDetailUrl(
        Spisovka $spisovka,
        Court $court,
        string $eventCode,
        int $eventOrder,
        ?Spisovka $owner = null,
        ?Court $ownerCourt = null,
        ?string $upstreamId = null,
    ): ?string
    {
        $params = $this->caseParams($spisovka, $court);
        if ($params === null) {
            return null;
        }
        $orgCourt = $ownerCourt ?? $court;
        if ($upstreamId !== null) {
            $params += ['udalostId' => $upstreamId];
        }
        $params += [
            'druhUdalosti' => $eventCode,
            'poradiUdalosti' => $eventOrder,
            'organizaceId' => $orgCourt->level === CourtLevel::Supreme
                ? 'NSJIMBM'
                : $orgCourt->kod,
        ];
        if ($owner !== null) {
            // The SPA fills the *Id fields only where they differ from the case.
            $params += array_filter([
                'cisloSenatuId' => $owner->senate !== $spisovka->senate ? $owner->senate : null,
                'druhVeciId' => $owner->registryNorm() !== $spisovka->registryNorm() ? $owner->registryNorm() : null,
                'bcVecId' => $owner->number !== $spisovka->number ? $owner->number : null,
                'rocnikId' => $owner->year !== $spisovka->year ? CaseYear::forApi($owner->year) : null,
            ], static fn($value) => $value !== null);
        }
        return self::EventBaseUrl . '?' . http_build_query($params);
    }


    /** @return array<string, mixed>|null */
    private function caseParams(Spisovka $spisovka, Court $court): ?array
    {
        $level = $court->level;

        $params = match ($level) {
            CourtLevel::District => [
                'typOrganizace' => 'VSECHNY_KRAJE',
                'okresniSoud' => $court->kod,
            ],
            CourtLevel::Regional, CourtLevel::High => [
                'typOrganizace' => 'VSECHNY_KRAJE',
                'druhOrganizace' => $court->kod,
            ],
            CourtLevel::Supreme => [
                'typOrganizace' => 'NEJVYSSI',
            ],
            CourtLevel::SupremeAdministrative => null,
        };
        if ($params === null) {
            return null;
        }

        return $params + [
            'cisloSenatu' => $spisovka->senate,
            'druhVeci' => $spisovka->registryNorm(),
            'bcVec' => $spisovka->number,
            'rocnik' => CaseYear::forApi($spisovka->year),
        ];
    }
}
