<?php declare(strict_types=1);

namespace App\Presentation\Spis;

use App\Model\Codelist\Court;
use App\Model\Infosoud\InfosoudCaseOverview;


/**
 * Everything the shared case header renders (@case-header.latte), as one
 * value. Three templates include that header and each of them used to declare
 * the dozen loose variables it reads - and each of them declared a slightly
 * different set (tech-debt ST-8). One typed object means one {varType} per
 * template and no way for the lists to drift apart.
 *
 * Values are display-ready: nsAttributes hold the text to print (multi-value
 * attributes already joined, see InfosoudEventAttribute::formatMulti()), so
 * the template never reshapes upstream data.
 */
final readonly class CaseHeaderView
{
    /**
     * @param array<string, string> $nsAttributes Supreme Court extras, display form
     * @param array<string, mixed>|null $nsChallenged case chip of the case under review
     */
    public function __construct(
        public Court $court,
        public string $spisovkaLabel,
        public string $caseSlug,
        public ?\DateTimeImmutable $infosoudAt,
        public InfosoudCaseOverview $overview,
        public ?string $subject,
        public ?string $collegium,
        public array $nsAttributes,
        public ?array $nsChallenged,
        public bool $isStale,
        public bool $isFavorite,
        public ?string $favoriteName,
    ) {
    }
}
