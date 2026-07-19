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
        $attributes = [];
        foreach (is_array($detail['atributy'] ?? null) ? $detail['atributy'] : [] as $attribute) {
            if (is_array($attribute) && isset($attribute['typ'])) {
                $attributes[(string) $attribute['typ']] = trim((string) ($attribute['hodnota'] ?? ''));
            }
        }
        $jed = array_filter(
            $attributes,
            static fn(string $type) => str_starts_with($type, 'JED_'),
            ARRAY_FILTER_USE_KEY,
        );
        if ($jed === []) {
            return null;
        }

        $startsAt = \DateTimeImmutable::createFromFormat('!d.m.Y H:i', $jed['JED_D_ZAC'] ?? '') ?: null;
        $clean = static fn(?string $value): ?string => ($value ?? '') !== '' && $value !== '-' ? $value : null;

        return new self(
            startsAt: $startsAt,
            room: $clean($jed['JED_SIN'] ?? null),
            type: $clean($jed['JED_DRUH'] ?? null),
            result: $clean($jed['JED_VYSLED'] ?? null),
            cancelled: ($jed['JED_ZRUS'] ?? '') === 'Ano',
        );
    }
}
