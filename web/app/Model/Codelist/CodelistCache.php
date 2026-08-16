<?php declare(strict_types=1);

namespace App\Model\Codelist;

use JakubBoucek\Hydrator\HydratorFactory;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Database\Explorer;


/**
 * Provides the codelist snapshot: a serialized graph of ready entities plus
 * their lookup maps, cached across requests (see docs/analyza-ciselniky.md).
 *
 * The hydrator stays the only DB -> entity path and runs solely on a cache
 * miss when the snapshot is built; a cache hit thaws the already-built graph
 * (native unserialize keeps the shared references between the lists and the
 * maps). Invalidation mirrors the Nette DI container: in debug mode the cache
 * depends on the mtimes of the entity/set/builder class files, in production
 * it is valid until the deploy purges temp/cache. A manual codelist migration
 * without a deploy therefore requires purging the cache by hand - every
 * codelist migration must say so in its header.
 *
 * Fail-open by design: a broken cache read is a miss, a failed write is
 * ignored - the cache is an optimization, never a dependency.
 */
final class CodelistCache
{
    private const string CacheKey = 'snapshot';

    private Cache $cache;
    private ?CodelistSnapshot $snapshot = null;


    public function __construct(
        private readonly Explorer $db,
        private readonly HydratorFactory $hydrators,
        Storage $storage,
        private readonly bool $debugMode,
    ) {
        $this->cache = new Cache($storage, 'App.Codelist');
    }


    public function snapshot(): CodelistSnapshot
    {
        return $this->snapshot ??= $this->load();
    }


    private function load(): CodelistSnapshot
    {
        try {
            $cached = $this->cache->load(self::CacheKey);
        } catch (\Throwable) {
            $cached = null;
        }
        if ($cached instanceof CodelistSnapshot) {
            return $cached;
        }

        $snapshot = $this->build();
        try {
            $this->cache->save(self::CacheKey, $snapshot, $this->dependencies());
        } catch (\Throwable) {
            // e.g. a read-only filesystem; the fresh snapshot is still served
        }
        return $snapshot;
    }


    private function build(): CodelistSnapshot
    {
        return new CodelistSnapshot(
            courts: $this->buildCourts(),
            registries: $this->buildRegistries(),
            courtPrefixes: $this->buildCourtPrefixes(),
            relationTypes: $this->buildRelationTypes(),
            generatedAt: new \DateTimeImmutable,
        );
    }


    private function buildCourts(): CourtSet
    {
        // The ordering (incl. the Czech collation on names) is the database's,
        // frozen into the snapshot - never rebuilt in PHP.
        $ordered = $this->hydrators->for(Court::class)
            ->fromDataSet($this->db->table('court')->order('level DESC, name'))
            ->collectList();

        $byKod = $bySlug = $byName = $byParent = [];
        foreach ($ordered as $court) {
            $byKod[$court->kod] = $court;
            $bySlug[$court->slug] = $court;
            $byName[mb_strtolower($court->name)] = $court;
            if ($court->parentKod !== null) {
                $byParent[$court->parentKod][] = $court;
            }
        }
        return new CourtSet($ordered, $byKod, $bySlug, $byName, $byParent);
    }


    private function buildRegistries(): RegistrySet
    {
        $rows = $this->hydrators->for(Registry::class)
            ->fromDataSet($this->db->table('registry')->order('code_norm, court_level'))
            ->collectList();

        $byNorm = $bySlug = [];
        $allNorms = [];
        foreach ($rows as $registry) {
            if (!isset($byNorm[$registry->codeNorm])) {
                $allNorms[] = $registry->codeNorm;
            }
            $byNorm[$registry->codeNorm][] = $registry;
            $bySlug[$registry->slug] ??= $registry;
        }
        return new RegistrySet($byNorm, $bySlug, $allNorms);
    }


    private function buildCourtPrefixes(): CourtPrefixSet
    {
        $byPrefix = [];
        foreach ($this->hydrators->for(CourtPrefix::class)->fromDataSet($this->db->table('court_prefix')) as $prefix) {
            $byPrefix[$prefix->prefix] = $prefix;
        }
        return new CourtPrefixSet($byPrefix);
    }


    private function buildRelationTypes(): RelationTypeSet
    {
        $byCode = [];
        foreach ($this->hydrators->for(RelationTypeEntry::class)->fromDataSet($this->db->table('relation_type')) as $entry) {
            $byCode[$entry->code] = $entry;
        }
        return new RelationTypeSet($byCode);
    }


    /**
     * In debug mode the snapshot auto-invalidates when any class it is made of
     * changes (the same idea as the DI container's auto-refresh); production
     * trusts the cache until the deploy purges temp/cache.
     *
     * @return array<string, mixed>|null
     */
    private function dependencies(): ?array
    {
        if (!$this->debugMode) {
            return null;
        }
        $classes = [
            CodelistSnapshot::class, CourtSet::class, RegistrySet::class,
            CourtPrefixSet::class, RelationTypeSet::class,
            Court::class, Registry::class, CourtPrefix::class, RelationTypeEntry::class,
            CourtLevel::class, CourtRegion::class, self::class,
        ];
        $files = [];
        foreach ($classes as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if ($file !== false) {
                $files[] = $file;
            }
        }
        return [Cache::Files => $files];
    }
}
