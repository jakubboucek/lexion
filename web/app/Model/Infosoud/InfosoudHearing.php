<?php declare(strict_types=1);

namespace App\Model\Infosoud;


/**
 * Hearing information parsed from a NAR_JED/ZRUS_JED event detail (the JED_*
 * attributes). A simple interim representation - hearings will eventually be
 * scraped separately via the jednani/vyhledej endpoint (see
 * docs/infosoud-api.md); until then this lets the system work with the
 * scheduled time, room and hearing type.
 */
final readonly class InfosoudHearing
{
    public function __construct(
        public ?\DateTimeImmutable $startsAt,
        public ?string $room,
        public ?string $type,
        public ?string $result,
        public bool $cancelled,
    ) {
    }


    /**
     * Extracts the hearing from an udalost/vyhledej response; null when the
     * detail carries no JED_* attributes at all.
     *
     * @param array<mixed> $detail
     */
    public static function fromEventDetail(array $detail): ?self
    {
        $jed = array_filter(
            InfosoudEventAttribute::mapFromDetail($detail),
            static fn(string $type) => str_starts_with($type, 'JED_'),
            ARRAY_FILTER_USE_KEY,
        );
        if ($jed === []) {
            return null;
        }

        return new self(
            startsAt: \DateTimeImmutable::createFromFormat('!d.m.Y H:i', (string) ($jed['JED_D_ZAC'] ?? '')) ?: null,
            room: $jed['JED_SIN'] ?? null,
            type: $jed['JED_DRUH'] ?? null,
            result: $jed['JED_VYSLED'] ?? null,
            cancelled: ($jed['JED_ZRUS'] ?? null) === 'Ano',
        );
    }
}
