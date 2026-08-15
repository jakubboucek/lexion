<?php declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Model\Codelist\Court;
use App\Model\Codelist\CourtRepository;
use App\Model\Codelist\RegistryRepository;
use App\Model\Infosoud\InfosoudEventAttribute;
use App\Model\Proceeding\CaseFile;
use App\Model\Proceeding\ProceedingRepository;
use App\Model\Spisovka\Spisovka;
use App\Model\Spisovka\SpisovkaFactory;
use App\Model\Spisovka\SpisovkaParseException;
use App\Model\Spisovka\SpisovkaParser;


/**
 * Builds the view model of a reference to another case - the chip rendered by
 * @spisovka.latte. It owns the single rule for when such a reference becomes a
 * link, so every place that mentions a foreign file number behaves the same
 * (tech-debt ST-1 step 1, ST-4).
 */
final readonly class CaseChipFactory
{
    public function __construct(
        private CourtRepository $courts,
        private RegistryRepository $registries,
        private ProceedingRepository $proceedings,
        private SpisovkaFactory $spisovkaFactory,
        private SpisovkaParser $parser,
    ) {
    }


    /**
     * One rule for every place a file number of another case appears: link to
     * its detail when the court is known, otherwise - as long as the registry
     * says it is a court case at all - offer it prefilled on the homepage
     * search, because we cannot address a case without its court. A reference
     * that is not a court case (a prosecutor file) gets no link at all.
     *
     * @return array<string, mixed>
     */
    public function chip(?Court $court, Spisovka $spisovka): array
    {
        $isCourtCase = $this->isCourtRegistry($spisovka);
        return [
            'label' => $spisovka->format(),
            'courtSlug' => $court?->slug,
            'courtName' => $court?->name,
            'slug' => $spisovka->toSlug(),
            'linkable' => $court !== null && $isCourtCase,
            'search' => $court === null && $isCourtCase ? $spisovka->format() : null,
        ];
    }


    /**
     * Turns file numbers quoted in an event attribute into chips.
     *
     * The value is upstream free text, so it is only treated as a case when it
     * parses AND its registry is a court one - "2 ZT 7 / 2025" is a prosecutor
     * file and stays plain text, exactly as it does in the related-cases table.
     *
     * The registry is canonicalised ("NC" -> "Nc") ONLY for a file number the
     * case is already known to be related to: there the codelist form is
     * certain. Otherwise the number is merely tidied up (separators, spacing)
     * and offered as a search on the homepage, since we do not know its court.
     *
     * @param list<string> $parts
     * @param array<string, ?string> $relatedCourts identity key => court kod (null = court unknown)
     * @return list<array<string, mixed>>|null null when nothing resolved to a case
     */
    public function references(array $parts, array $relatedCourts, ?Court $courtHint = null): ?array
    {
        $cases = [];
        foreach ($parts as $part) {
            try {
                $parsed = $this->parser->parse($part);
            } catch (SpisovkaParseException) {
                $cases[] = ['text' => $part];
                continue;
            }
            if (!$this->isCourtRegistry($parsed)) {
                $cases[] = ['text' => $part]; // not a court case (prosecutor file, ...)
                continue;
            }

            $key = $parsed->registryNorm() . '|' . $parsed->senate . '|' . $parsed->number . '|' . $parsed->year;
            $isRelated = array_key_exists($key, $relatedCourts);
            $courtKod = $relatedCourts[$key] ?? null;
            // A sibling attribute may name the court (PR_VEC_NS is the file
            // number at the court named in ODVOL_SOUD), which is the only way
            // to resolve it before the case itself is known to us.
            $court = $courtKod !== null ? $this->courts->getByKod($courtKod) : $courtHint;
            // Codelist display form only where we know which case this is.
            $spisovka = $isRelated || $court !== null
                ? $this->spisovkaFactory->fromCase($parsed->senate, $parsed->registryNorm(), $parsed->number, $parsed->year)
                : $parsed;

            $cases[] = $this->chip($court, $spisovka);
        }
        return array_any($cases, static fn(array $case): bool => isset($case['label'])) ? $cases : null;
    }


    /**
     * Court named by the sibling attribute of a case reference, if the codelist
     * knows it under that name.
     *
     * @param array<string, ?string> $values attribute type => cleaned value
     */
    public function courtNamedIn(array $values, string $type): ?Court
    {
        $namedBy = InfosoudEventAttribute::courtNamedBy($type);
        $name = $namedBy !== null ? ($values[$namedBy] ?? null) : null;
        return $name !== null ? $this->courts->getByName($name) : null;
    }


    /**
     * Case files we hold of the referenced cases, keyed by CaseFile::key() -
     * one query for a whole page worth of chips. References without a court
     * cannot be addressed and are skipped.
     *
     * @param list<array{courtKod: ?string, spisovka: Spisovka, ...}> $references any further keys are the caller's
     * @return array<string, CaseFile>
     */
    public function storedCases(array $references): array
    {
        $asked = [];
        foreach ($references as $reference) {
            if ($reference['courtKod'] === null) {
                continue;
            }
            $court = $this->courts->getByKod($reference['courtKod']);
            if ($court !== null) {
                $asked[] = [(string) $court->kod, $reference['spisovka']];
            }
        }
        return $this->proceedings->findByCases($asked);
    }


    /**
     * A registry missing from the codelist cannot belong to a court case
     * (prosecutor's files etc.) - such a reference must not become a link,
     * the target detail could never exist.
     */
    public function isCourtRegistry(Spisovka $spisovka): bool
    {
        return $this->registries->displayFromNorm($spisovka->registryNorm()) !== null;
    }
}
