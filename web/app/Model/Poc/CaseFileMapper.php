<?php declare(strict_types=1);

namespace App\Model\Poc;

use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\HydratorFactory;


/**
 * DB row <-> CaseFile mapping via the jakubboucek/hydrator package.
 *
 * The factory is configured once with the format (NetteDatabase: values are
 * already typed on both sides, so instances pass through) and the
 * application time zone, which every hydrated date-time is normalized into.
 * `for()` returns the per-entity hydrator; its metadata is reflected once and
 * reused for every row.
 */
final class CaseFileMapper
{
    /** @var Hydrator<CaseFile> */
    private readonly Hydrator $hydrator;


    public function __construct()
    {
        $factory = new HydratorFactory(
            format: NetteDatabase::class,
            timeZone: new \DateTimeZone('Europe/Prague'),
        );
        $this->hydrator = $factory->for(CaseFile::class);
    }


    /** @param array<string, mixed> $row */
    public function fromRow(array $row): CaseFile
    {
        return $this->hydrator->fromData($row);
    }


    /** @return array<string, mixed> */
    public function toRow(CaseFile $entity): array
    {
        return $this->hydrator->toData($entity);
    }
}
