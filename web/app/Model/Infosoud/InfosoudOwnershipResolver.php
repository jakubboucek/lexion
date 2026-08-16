<?php declare(strict_types=1);

namespace App\Model\Infosoud;

use App\Model\Codelist\CourtCodeResolver;
use App\Model\Spisovka\CaseYear;


/**
 * Decides whether an event's znackaId identifies a given case - the single
 * home of the quirky five-way comparison shared by the sync (picking the
 * first own event) and the projection (own vs. foreign event rows).
 *
 * Quirks encoded here: the org code may be an infosoud-internal alias
 * (NS -> NSJIMBM), NS events carry senate 0 instead of the real senate
 * number, and a two-digit upstream year is always 20th century.
 */
final readonly class InfosoudOwnershipResolver
{
    public function __construct(
        private CourtCodeResolver $courtCodes,
    ) {
    }


    /** @param array<mixed> $znackaId the udalosti[].znackaId object */
    public function isOwn(
        array $znackaId,
        string $courtKod,
        int $senate,
        string $registryNorm,
        int $bcNumber,
        int $year,
    ): bool
    {
        $eventSenate = (int) ($znackaId['cisloSenatu'] ?? -1);
        return $this->courtCodes->resolveKod((string) ($znackaId['organizace'] ?? '')) === $courtKod
            && ($eventSenate === $senate || $eventSenate === 0)
            && mb_strtoupper((string) ($znackaId['druhVeci'] ?? '')) === $registryNorm
            && (int) ($znackaId['bcVec'] ?? -1) === $bcNumber
            && CaseYear::fromUpstream((int) ($znackaId['rocnik'] ?? -1)) === $year;
    }
}
