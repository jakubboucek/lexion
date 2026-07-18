<?php declare(strict_types=1);

namespace App\Model\Infosoud;

use App\Model\Codelist\CourtLevel;
use App\Model\Spisovka\Spisovka;
use Nette\Database\Table\ActiveRow;


/**
 * Builds public deep-links into the infosoud SPA (see docs/infosoud-api.md).
 */
final class InfosoudLinkBuilder
{
    private const string BaseUrl = 'https://infosoud.gov.cz/InfoSoud/detail-rizeni';


    /** Returns null when the court is not covered by infosoud (NSS). */
    public function detailUrl(Spisovka $spisovka, ActiveRow $court): ?string
    {
        $level = CourtLevel::from($court->level);

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

        $params += [
            'cisloSenatu' => $spisovka->senate,
            'druhVeci' => $spisovka->registryNorm(),
            'bcVec' => $spisovka->number,
            'rocnik' => $spisovka->year,
        ];

        return self::BaseUrl . '?' . http_build_query($params);
    }
}
