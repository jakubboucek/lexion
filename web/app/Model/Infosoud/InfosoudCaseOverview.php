<?php declare(strict_types=1);

namespace App\Model\Infosoud;

use JakubBoucek\Hydrator\Struct\BaseStruct;


/**
 * Typed view of the case-level scalars in the stored infosoud overview JSON
 * (proceeding.infosoud_json). The knowledge of the upstream shape lives here
 * and nowhere else: templates and services read the typed accessors, never
 * the decoded array (tech-debt ST-3).
 *
 * The public properties are named by the upstream keys on purpose - that is
 * the BaseStruct field mapping - and are the parsing surface only; the
 * accessors below are the interface, and normalize the upstream habit of
 * blank strings to null. Everything else in the JSON (udalosti, navazneVeci,
 * firstEventDetail) is read through the event/relation projections, not here.
 *
 * Built via fromJson() straight from the raw column; a NULL column yields an
 * empty instance, so callers never branch on null. Read-only by convention:
 * the snapshot philosophy (CLAUDE.md) means this struct is never written
 * back - the raw JSON column stays the source of truth.
 */
final class InfosoudCaseOverview extends BaseStruct
{
    public ?string $stav = null;
    public ?string $stavDatum = null;
    public ?string $napad = null;
    public ?string $nadrizenaOrganizace = null;


    /** Current state of the case ("stav"), e.g. "nevyřízená věc". */
    public function status(): ?string
    {
        return self::filled($this->stav);
    }


    /** Since when the state holds ("stavDatum"), verbatim display form. */
    public function statusDate(): ?string
    {
        return self::filled($this->stavDatum);
    }


    /** Kind of case intake ("napad" = druh nápadu). */
    public function intakeKind(): ?string
    {
        return self::filled($this->napad);
    }


    /** Name of the superior court ("nadrizenaOrganizace"). */
    public function superiorCourtName(): ?string
    {
        return self::filled($this->nadrizenaOrganizace);
    }


    private static function filled(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;
        return $value !== '' ? $value : null;
    }
}
