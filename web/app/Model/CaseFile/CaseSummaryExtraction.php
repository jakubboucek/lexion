<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Infosoud\InfosoudHearing;


/**
 * Derives the summary columns of a case file and its events from the raw
 * upstream payloads. The raw JSON columns are verbatim snapshots meant for
 * writing, integrity checks and analysis - never for rendering a page, so
 * everything the UI needs is materialized into columns at write time and this
 * is where that translation lives.
 *
 * Every method returns a PATCH entity: only the properties it owns are
 * initialized, so the result can be merged into a bigger patch or handed to a
 * repository as is (see the patch semantics in docs/architektura.md). Values
 * the source leaves blank or states as "-" become an explicit NULL rather
 * than an uninitialized property - a case that stops stating its subject must
 * clear the column, not keep the stale one.
 *
 * Purely static and stateless: no DI registration (and deliberately not named
 * with a service suffix - see the registration rules in CLAUDE.md).
 */
final class CaseSummaryExtraction
{
    /**
     * Case-level scalars of the overview payload (case_file.infosoud_json).
     *
     * @param array<mixed> $case decoded overview payload
     */
    public static function overviewPatch(array $case): CaseFile
    {
        $patch = new CaseFile;
        $patch->status = InfosoudEventAttribute::cleanValue($case['stav'] ?? null);
        $patch->statusDate = self::parseDate(InfosoudEventAttribute::cleanValue($case['stavDatum'] ?? null));
        $patch->intakeKind = InfosoudEventAttribute::cleanValue($case['napad'] ?? null);
        return $patch;
    }


    /**
     * Subject of the case (PREDM_RIZ) as stated by an event detail - the
     * opening event carries it. A detail without the attribute clears the
     * column: the payload is the whole truth about that event.
     *
     * @param array<mixed> $detail decoded event detail payload
     */
    public static function subjectPatch(array $detail): CaseFile
    {
        $patch = new CaseFile;
        $patch->subject = InfosoudEventAttribute::mapFromDetail($detail)['PREDM_RIZ'] ?? null;
        return $patch;
    }


    /**
     * Hearing values of an event detail (JED_* attributes), parsed by the same
     * InfosoudHearing the event page uses. A detail carrying no JED_*
     * attributes - or no detail at all - clears all three columns.
     *
     * @param array<mixed>|null $detail decoded event detail payload
     */
    public static function hearingPatch(?array $detail): CaseFileEvent
    {
        $hearing = $detail !== null ? InfosoudHearing::fromEventDetail($detail) : null;
        $patch = new CaseFileEvent;
        $patch->hearingAt = $hearing?->startsAt;
        $patch->hearingRoom = $hearing?->room;
        $patch->hearingType = $hearing?->type;
        return $patch;
    }


    /**
     * The event whose detail states the case-level attributes: the opening
     * record, else the earliest own record that has a detail. Mirrors the pick
     * CaseFileSyncService makes upstream (ZAHAJ_RIZ first, otherwise the
     * earliest own event), but over stored rows - foreign records describe
     * another case and thin rows state nothing.
     *
     * @param list<CaseFileEvent> $events timeline of one case
     */
    public static function firstOwnDetailed(array $events): ?CaseFileEvent
    {
        return self::pickFirstOwn($events, requireDetail: true);
    }


    /**
     * The record the case-level attributes WOULD come from once its detail is
     * fetched - the same pick as firstOwnDetailed(), minus the requirement
     * that the detail is already there. This is what a fetcher asks for when
     * deciding whether the case still owes us that one request.
     *
     * @param list<CaseFileEvent> $events timeline of one case
     */
    public static function firstOwn(array $events): ?CaseFileEvent
    {
        return self::pickFirstOwn($events, requireDetail: false);
    }


    /**
     * @param list<CaseFileEvent> $events timeline of one case
     * @param bool $requireDetail skip rows that carry no detail yet
     */
    private static function pickFirstOwn(array $events, bool $requireDetail): ?CaseFileEvent
    {
        $earliest = null;
        foreach ($events as $event) {
            // A materialized hearing term is not a record of its own upstream:
            // the sync picks from the top-level timeline, so must we.
            if ($event->isForeign() || $event->parentEventOrder !== null) {
                continue;
            }
            if ($requireDetail && $event->detailJson === null) {
                continue;
            }
            if ($event->eventCode === 'ZAHAJ_RIZ') {
                return $event;
            }
            if ($earliest === null || self::isEarlier($event, $earliest)) {
                $earliest = $event;
            }
        }
        return $earliest;
    }


    /** Upstream states dates as "d.m.Y"; anything else is not a date. */
    private static function parseDate(?string $value): ?\DateTimeImmutable
    {
        return $value !== null
            ? (\DateTimeImmutable::createFromFormat('!d.m.Y', $value) ?: null)
            : null;
    }


    /**
     * Timeline order of two own records: by date, undated last, poradi as the
     * tie-breaker within a day (see docs/analyza-udalosti.md, §3).
     */
    private static function isEarlier(CaseFileEvent $event, CaseFileEvent $than): bool
    {
        return [$event->eventDate?->format('Y-m-d') ?? '9999-99-99', $event->eventOrder ?? PHP_INT_MAX]
            < [$than->eventDate?->format('Y-m-d') ?? '9999-99-99', $than->eventOrder ?? PHP_INT_MAX];
    }
}
