<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Judicial region (soudní kraj per the 1960 territorial division, still used
 * by the justice system) as stored in the court.region column. infoSoud
 * encodes it as the middle three characters of the court code
 * (OSSEMOP → SEM). Nationwide courts (NS, NSS) have no region.
 */
enum CourtRegion: string
{
    case Prague = 'PHA';
    case CentralBohemia = 'STC';
    case SouthBohemia = 'JIC';
    case WestBohemia = 'ZPC';
    case NorthBohemia = 'SCE';
    case EastBohemia = 'VYC';
    case SouthMoravia = 'JIM';
    case NorthMoravia = 'SEM';


    public function label(): string
    {
        return match ($this) {
            self::Prague => 'Praha',
            self::CentralBohemia => 'střední Čechy',
            self::SouthBohemia => 'jižní Čechy',
            self::WestBohemia => 'západní Čechy',
            self::NorthBohemia => 'severní Čechy',
            self::EastBohemia => 'východní Čechy',
            self::SouthMoravia => 'jižní Morava',
            self::NorthMoravia => 'severní Morava',
        };
    }
}
