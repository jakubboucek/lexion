<?php declare(strict_types=1);

namespace App\Model\Codelist;


/**
 * Serializable index of the court codelist inside CodelistSnapshot: the
 * DB-ordered list plus lookup maps. The maps hold references to the same
 * Court instances - native serialization stores every entity once and the
 * maps as references, so they cost almost nothing in the cache. A dumb data
 * holder: query logic stays in CourtRepository.
 */
final readonly class CourtSet
{
    /**
     * @param list<Court> $ordered courts in the DB order (level DESC, name; the
     *   collation-aware ordering is baked in at build time, never redone in PHP)
     * @param array<string, Court> $byKod keyed by kod (uppercase)
     * @param array<string, Court> $bySlug keyed by slug (lowercase)
     * @param array<string, Court> $byName keyed by mb_strtolower(name)
     * @param array<string, list<Court>> $byParent children grouped by parent
     *   kod, in the DB order (no consumer yet - part of the agreed snapshot
     *   shape, see docs/analyza-ciselniky.md)
     */
    public function __construct(
        public array $ordered,
        public array $byKod,
        public array $bySlug,
        public array $byName,
        public array $byParent,
    ) {
    }
}
