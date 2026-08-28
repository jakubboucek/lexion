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


    /**
     * Series identity in the court's own notation: senate BEFORE registry
     * (a senate is "35 C"), then year - "OSSEMOS 35 C 2026". An offset block
     * appends @from. See CLAUDE.md, the identity note.
     */
    public function label(): string
    {
        $block = $this->from > 1 ? ' @' . $this->from : '';
        return sprintf('%s %d %s %d%s', $this->courtKod, $this->senate, $this->registryNorm, $this->year, $block);
    }


    /** One case's full spisová značka: "OSSEMOS 35 C 138/2026" (senate registry number/year). */
    public function caseLabel(int $number): string
    {
        return sprintf('%s %d %s %d/%d', $this->courtKod, $this->senate, $this->registryNorm, $number, $this->year);
    }
}
