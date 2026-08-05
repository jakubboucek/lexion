<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Codelist of courts (see migration 2026-07-18-00). Read-only in practice -
 * rows change by migration only. Backed by the cached snapshot
 * (CodelistCache): every lookup is an array access on prebuilt maps, no SQL.
 *
 * Key lookups are case-insensitive (kod/slug/name are normalized on both
 * sides) but - unlike the former DB queries under the *_ci collation -
 * accent-sensitive. That is a deliberate tightening: upstream codes and our
 * slugs are ASCII, and infosoud names courts with the exact codelist wording.
 */
final readonly class CourtRepository
{
    public function __construct(
        private CodelistCache $codelists,
    ) {
    }


    /**
     * All courts, higher instances first, then by name. The ordering is the
     * database's (collation-aware), frozen into the snapshot.
     *
     * @return list<Court>
     */
    public function findAll(): array
    {
        return $this->codelists->snapshot()->courts->ordered;
    }


    public function getByKod(string $kod): ?Court
    {
        return $this->codelists->snapshot()->courts->byKod[strtoupper($kod)] ?? null;
    }


    public function getBySlug(string $slug): ?Court
    {
        return $this->codelists->snapshot()->courts->bySlug[strtolower($slug)] ?? null;
    }


    /**
     * Court by its exact name. Infosoud names courts in free-text attributes
     * (ODVOL_SOUD = "Městský soud Praha") with the same wording as the codelist,
     * which is the only way to resolve the court of a referenced case there.
     */
    public function getByName(string $name): ?Court
    {
        return $this->codelists->snapshot()->courts->byName[mb_strtolower(trim($name))] ?? null;
    }


    /**
     * Courts of the given levels, in the same order as findAll().
     *
     * @param list<CourtLevel> $levels
     * @return list<Court>
     */
    public function findByLevels(array $levels): array
    {
        $wanted = array_flip(array_map(static fn(CourtLevel $level): string => $level->value, $levels));
        return array_values(array_filter(
            $this->codelists->snapshot()->courts->ordered,
            static fn(Court $court): bool => isset($wanted[$court->level->value]),
        ));
    }
}
