<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Relation types between case files - the code side of the relation_type DB
 * codelist (seeded by migration 2026-07-19-04; labels incl. the reverse ones
 * live in the table). Keep the enum and the seed in step: the projection
 * insert would hit the FK for a value missing in the table.
 *
 * SOUVISEJICI is not a manual type - it is the automatic fallback the
 * projection assigns to any foreign event code without a dedicated type.
 */
enum RelationType: string
{
    case Odvolani = 'ODVOLANI';
    case NadRizeni = 'NAD_RIZENI';
    case DovolRiz = 'DOVOL_RIZ';
    case PrevdSpis = 'PREVD_SPIS';
    case NavaznaVec = 'NAVAZNA_VEC';
    case PredVec = 'PRED_VEC';
    case Souvisejici = 'SOUVISEJICI';


    /** Relation type projected for a foreign event of the given code. */
    public static function forEventCode(string $eventCode): self
    {
        return self::tryFrom($eventCode) ?? self::Souvisejici;
    }
}
