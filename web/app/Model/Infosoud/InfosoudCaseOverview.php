<?php declare(strict_types=1);

namespace App\Model\Infosoud;

use JakubBoucek\Hydrator\Struct\RawJsonStruct;


/**
 * Typed view of the case-level scalars in the stored infosoud overview JSON
 * (case_file.infosoud_json). The knowledge of the upstream shape lives here
 * and nowhere else: templates and services read the typed accessors, never
 * the decoded array (tech-debt ST-3).
 *
 * A RawJsonStruct on purpose - the document is FOREIGN content holding far
 * more than these four fields (udalosti, navazneVeci, firstEventDetail...,
 * read through the event/relation projections). The verbatim string stays
 * the single source of truth and would survive a load-store roundtrip
 * byte-exact; a declared-fields struct would re-render only what it maps
 * and destroy the snapshot.
 *
 * Built via fromJson() straight from the raw column; a NULL column yields an
 * empty, fully readable instance, so callers never branch on null. The
 * accessors normalize the upstream habit of blank strings to null.
 */
final class InfosoudCaseOverview extends RawJsonStruct
{
    /** Current state of the case ("stav"), e.g. "nevyřízená věc". */
    public function status(): ?string
    {
        return $this->filled('stav');
    }


    /** Since when the state holds ("stavDatum"), verbatim display form. */
    public function statusDate(): ?string
    {
        return $this->filled('stavDatum');
    }


    /** Kind of case intake ("napad" = druh nápadu). */
    public function intakeKind(): ?string
    {
        return $this->filled('napad');
    }


    /** Name of the superior court ("nadrizenaOrganizace"). */
    public function superiorCourtName(): ?string
    {
        return $this->filled('nadrizenaOrganizace');
    }


    private function filled(string $key): ?string
    {
        $value = $this->tryGetString($key);
        $value = $value !== null ? trim($value) : null;
        return $value !== '' ? $value : null;
    }
}
