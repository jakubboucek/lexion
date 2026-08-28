<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * One number-series block asked of the scanner: the identity, the block start
 * `from` (default 1), an optional hard ceiling `to` never to be crossed, and an
 * optional end estimate hint. Parsed from CLI args or a list file by
 * bin/infosoud-scan-series.php; see docs/navrh-sken-rad.md.
 */
final readonly class SeriesScanTarget
{
    public function __construct(
        public string $courtKod,
        public string $registryNorm,
        public int $senate,
        public int $year,
        public int $from = 1,
        public ?int $to = null,
        public ?int $estimate = null,
    ) {
        if ($this->from < 1) {
            throw new \InvalidArgumentException('from must be at least 1.');
        }
        if ($this->to !== null && $this->to < $this->from) {
            throw new \InvalidArgumentException('to must not be below from.');
        }
    }


    public function label(): string
    {
        $block = $this->from > 1 ? '@' . $this->from : '';
        return sprintf('%s %s %d/%d%s', $this->courtKod, $this->registryNorm, $this->senate, $this->year, $block);
    }
}
