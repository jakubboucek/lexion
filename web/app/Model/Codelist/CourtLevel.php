<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Court level as stored in the court.level and registry.court_level columns.
 */
enum CourtLevel: string
{
    case District = 'os';
    case Regional = 'ks';
    case High = 'vs';
    case Supreme = 'ns';
    case SupremeAdministrative = 'nss';


    public function label(): string
    {
        return match ($this) {
            self::District => 'okresní soud',
            self::Regional => 'krajský soud',
            self::High => 'vrchní soud',
            self::Supreme => 'Nejvyšší soud',
            self::SupremeAdministrative => 'Nejvyšší správní soud',
        };
    }


    /** Whether infosoud covers cases at this court level. */
    public function isOnInfosoud(): bool
    {
        return $this !== self::SupremeAdministrative;
    }
}
