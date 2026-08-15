<?php declare(strict_types=1);

namespace App\Presentation\Spis;


/**
 * Everything the event page renders below the shared case header, as one
 * value - same reason as CaseHeaderView: one {varType} instead of a dozen
 * loose variables that drift (tech-debt ST-8).
 */
final readonly class EventView
{
    /**
     * @param array<string, mixed>|null $owner case chip of the foreign case owning the record
     * @param list<array<string, mixed>> $attributes rows of the attribute table
     * @param list<array<string, mixed>> $navazneVeci linked cases from the detail
     * @param bool $navazneFirst infosoud renders them above attributes for DOVOL_RIZ
     * @param bool $fetchable the record can be asked about upstream at all
     */
    public function __construct(
        public string $label,
        public ?string $description,
        public ?\DateTimeImmutable $date,
        public bool $cancelled,
        public ?\DateTimeImmutable $fetchedAt,
        public ?array $owner,
        public array $attributes,
        public array $navazneVeci,
        public bool $navazneFirst,
        public ?string $infosoudUrl,
        public bool $fetchable,
    ) {
    }
}
