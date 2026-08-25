<?php declare(strict_types=1);

namespace App\Model\Integrity;

use App\Model\Log\LogService;
use Nette\Database\Explorer;


/**
 * The data-integrity checks of the System section (docs/navrh-integrita-dat.md,
 * step 1). Read-only by design: running the checks changes nothing and is
 * safe anywhere, production included. Repairs are a separate, explicit step
 * and never a side effect of looking.
 *
 * WHY THESE CHECKS EXIST. The invariants they watch used to have a single
 * writer each; the sync added a second one (and hand-run SQL migrations were
 * always a third hand), so drift is no longer structurally impossible - see
 * the writers table in docs/navrh-integrita-dat.md. The baseline was measured
 * clean on 2026-08-22, so any nonzero discrepancy is a real signal, not noise.
 *
 * WHAT MUST NOT BECOME A CHECK. Legitimate gaps: relation targets outside our
 * codelists (prosecutor files ZK/ZT), `ref_*` pointing at cases not on
 * record, rooms without `retired_at`... Reporting those would drown the two
 * real categories in noise - the same reasoning that made codelist
 * differences a warning in the sync instead of a veto.
 *
 * The checks are declarative data (see IntegrityCheck), so the set is
 * enumerable and a slug can be referenced from the log or repair tooling.
 * Czech texts live here because they are UI labels of the checks, same as
 * flash messages in presenters.
 */
final readonly class IntegrityService
{
    public function __construct(
        private Explorer $db,
        private LogService $log,
    ) {
    }


    /** @return list<IntegrityCheckResult> in the declared order: discrepancies first */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks() as $check) {
            $count = (int) $this->db->fetchField($check->countSql);
            $samples = [];
            if ($count > 0 && $check->samplesSql !== null) {
                foreach ($this->db->fetchAll($check->samplesSql) as $row) {
                    $samples[] = (string) $row->sample;
                }
            }
            $results[] = new IntegrityCheckResult($check, $count, $samples);
        }
        return $results;
    }


    /**
     * Writes the current state as one instant log record (`integrity`/
     * `check`): counts per slug plus the defect total, everything in `data` -
     * an instant record has all its facts at write time. This is what makes
     * incompleteness *trends* observable at all.
     *
     * @param list<IntegrityCheckResult> $results
     */
    public function record(array $results): void
    {
        $counts = [];
        $defects = 0;
        foreach ($results as $result) {
            $counts[$result->check->slug] = $result->count;
            $defects += $result->isDefect() ? 1 : 0;
        }
        $this->log->log(
            IntegrityLogKind::Check,
            result: $defects === 0 ? 'clean' : 'defects',
            message: $defects === 0 ? null : "$defects check(s) nonzero in the discrepancy category",
            data: ['counts' => $counts, 'defects' => $defects],
        );
    }


    /**
     * The check definitions. Order is presentation order within the page.
     *
     * @return list<IntegrityCheck>
     */
    private function checks(): array
    {
        return [
            // ---- discrepancies: must be zero --------------------------------
            new IntegrityCheck(
                slug: 'event-projection-count',
                category: IntegrityCategory::Discrepancy,
                title: 'Projekce událostí nesedí s raw JSON',
                description: 'Počet událostí v projekci se u spisu liší od počtu v uloženém '
                    . 'infosoud JSON. Projekce má dva zapisovatele (projekce a sync) — tohle '
                    . 'hlídá, že se nerozešly. Materializované termíny vícetermínových jednání '
                    . 'se nepočítají, v top-level udalosti[] nejsou. '
                    . 'Oprava = přeprojektovat spis (destruktivní, přes plán).',
                countSql: "SELECT COUNT(*) FROM case_file c
                    WHERE c.infosoud_json IS NOT NULL
                      AND COALESCE(JSON_LENGTH(c.infosoud_json, '$.udalosti'), 0)
                          <> (SELECT COUNT(*) FROM case_file_event e
                              WHERE e.case_file_id = c.id AND e.source = 'infosoud'
                                AND e.parent_event_order IS NULL)",
                samplesSql: "SELECT CONCAT(c.court_kod, ' ', c.senate, ' ', c.registry_norm, ' ',
                        c.bc_number, '/', c.year, ' (json ',
                        COALESCE(JSON_LENGTH(c.infosoud_json, '$.udalosti'), 0), ' vs projekce ',
                        (SELECT COUNT(*) FROM case_file_event e
                         WHERE e.case_file_id = c.id AND e.source = 'infosoud'
                           AND e.parent_event_order IS NULL), ')') AS sample
                    FROM case_file c
                    WHERE c.infosoud_json IS NOT NULL
                      AND COALESCE(JSON_LENGTH(c.infosoud_json, '$.udalosti'), 0)
                          <> (SELECT COUNT(*) FROM case_file_event e
                              WHERE e.case_file_id = c.id AND e.source = 'infosoud'
                                AND e.parent_event_order IS NULL)
                    LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'case-summary-drift',
                category: IntegrityCategory::Discrepancy,
                title: 'Sloupce stavu spisu nesedí s raw JSON',
                description: 'Sloupce status/status_date/intake_kind se u spisu liší od hodnot '
                    . 'v uloženém infosoud JSON, ze kterého jsou odvozené. Zapisuje je sync '
                    . 'při každém uložení payloadu — rozdíl znamená, že se zápis nedostal '
                    . 'k sloupcům (starý řádek, ruční zásah do JSON). '
                    . 'Oprava = aktualizovat spis (přepíše obojí ze stejné odpovědi).',
                countSql: "SELECT COUNT(*) FROM case_file c
                    WHERE c.infosoud_json IS NOT NULL
                      AND NOT (c.status <=> NULLIF(NULLIF(TRIM(JSON_VALUE(c.infosoud_json, '$.stav')), ''), '-')
                           AND c.status_date <=> STR_TO_DATE(JSON_VALUE(c.infosoud_json, '$.stavDatum'), '%d.%m.%Y')
                           AND c.intake_kind <=> NULLIF(NULLIF(TRIM(JSON_VALUE(c.infosoud_json, '$.napad')), ''), '-'))",
                samplesSql: "SELECT CONCAT(c.court_kod, ' ', c.senate, ' ', c.registry_norm, ' ',
                        c.bc_number, '/', c.year, ' (sloupec ', COALESCE(c.status, 'NULL'),
                        ' vs json ', COALESCE(JSON_VALUE(c.infosoud_json, '$.stav'), 'NULL'), ')') AS sample
                    FROM case_file c
                    WHERE c.infosoud_json IS NOT NULL
                      AND NOT (c.status <=> NULLIF(NULLIF(TRIM(JSON_VALUE(c.infosoud_json, '$.stav')), ''), '-')
                           AND c.status_date <=> STR_TO_DATE(JSON_VALUE(c.infosoud_json, '$.stavDatum'), '%d.%m.%Y')
                           AND c.intake_kind <=> NULLIF(NULLIF(TRIM(JSON_VALUE(c.infosoud_json, '$.napad')), ''), '-'))
                    LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'hearing-columns-drift',
                category: IntegrityCategory::Discrepancy,
                title: 'Sloupce jednání nesedí s detailem události',
                description: 'Sloupce hearing_room/hearing_type se u události liší od atributů '
                    . 'JED_SIN/JED_DRUH v jejím uloženém detailu, ze kterých jsou odvozené '
                    . '(hearing_at se nekontroluje — datum se v SQL a PHP parsuje jinak přísně). '
                    . 'Oprava = stáhnout detail události znovu.',
                countSql: "SELECT COUNT(*) FROM case_file_event e
                    WHERE e.detail_json IS NOT NULL
                      AND NOT (e.hearing_room <=> NULLIF(NULLIF(TRIM(JSON_VALUE(e.detail_json,
                                REPLACE(JSON_UNQUOTE(JSON_SEARCH(e.detail_json, 'one', 'JED_SIN')), '.typ', '.hodnota'))), ''), '-')
                           AND e.hearing_type <=> NULLIF(NULLIF(TRIM(JSON_VALUE(e.detail_json,
                                REPLACE(JSON_UNQUOTE(JSON_SEARCH(e.detail_json, 'one', 'JED_DRUH')), '.typ', '.hodnota'))), ''), '-'))",
                samplesSql: "SELECT CONCAT('case_file #', e.case_file_id, ' ', e.event_code, ' poradi ',
                        COALESCE(e.event_order, '-'), ' (sloupec ', COALESCE(e.hearing_room, 'NULL'), ')') AS sample
                    FROM case_file_event e
                    WHERE e.detail_json IS NOT NULL
                      AND NOT (e.hearing_room <=> NULLIF(NULLIF(TRIM(JSON_VALUE(e.detail_json,
                                REPLACE(JSON_UNQUOTE(JSON_SEARCH(e.detail_json, 'one', 'JED_SIN')), '.typ', '.hodnota'))), ''), '-')
                           AND e.hearing_type <=> NULLIF(NULLIF(TRIM(JSON_VALUE(e.detail_json,
                                REPLACE(JSON_UNQUOTE(JSON_SEARCH(e.detail_json, 'one', 'JED_DRUH')), '.typ', '.hodnota'))), ''), '-'))
                    LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'event-without-source-json',
                category: IntegrityCategory::Discrepancy,
                title: 'Události u spisu bez zdrojového JSON',
                description: 'Spis má řádky v projekci událostí, ale žádný uložený infosoud JSON, '
                    . 'ze kterého by šly odvodit. Projekce visí ve vzduchu.',
                countSql: "SELECT COUNT(*) FROM case_file_event e
                    JOIN case_file c ON c.id = e.case_file_id
                    WHERE c.infosoud_json IS NULL",
                samplesSql: "SELECT DISTINCT CONCAT(c.court_kod, ' ', c.senate, ' ', c.registry_norm, ' ',
                        c.bc_number, '/', c.year) AS sample
                    FROM case_file_event e JOIN case_file c ON c.id = e.case_file_id
                    WHERE c.infosoud_json IS NULL LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'case-without-any-source',
                category: IntegrityCategory::Discrepancy,
                title: 'Spis bez dat z jakéhokoli zdroje',
                description: 'Řádek spisovny bez infosoud i ISIR payloadu — takový nemá jak vzniknout.',
                countSql: 'SELECT COUNT(*) FROM case_file WHERE infosoud_json IS NULL AND isir_json IS NULL',
                samplesSql: "SELECT CONCAT(court_kod, ' ', senate, ' ', registry_norm, ' ',
                        bc_number, '/', year) AS sample
                    FROM case_file WHERE infosoud_json IS NULL AND isir_json IS NULL LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'foreign-event-key-collision',
                category: IntegrityCategory::Discrepancy,
                title: 'Kolize párovacího klíče cizích událostí',
                description: 'Dvě cizí události téhož spisu se shodují v celém párovacím klíči '
                    . '(kód, pořadí, vlastnická značka). Unikátní index je nehlídá (NULL únik) '
                    . 'a sync by je nepároval deterministicky.',
                countSql: "SELECT COUNT(*) FROM (
                    SELECT 1 FROM case_file_event
                    WHERE ref_court_kod IS NOT NULL
                    GROUP BY case_file_id, source, event_code, event_order,
                             ref_court_kod, ref_registry_norm, ref_senate, ref_bc_number, ref_year
                    HAVING COUNT(*) > 1) x",
                samplesSql: "SELECT CONCAT('case_file #', case_file_id, ' ', event_code, ' poradi ',
                        COALESCE(event_order, '-'), ' (', COUNT(*), '×)') AS sample
                    FROM case_file_event
                    WHERE ref_court_kod IS NOT NULL
                    GROUP BY case_file_id, source, event_code, event_order,
                             ref_court_kod, ref_registry_norm, ref_senate, ref_bc_number, ref_year
                    HAVING COUNT(*) > 1 LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'relation-source-case-missing',
                category: IntegrityCategory::Discrepancy,
                title: 'Vazba bez zdrojového spisu',
                description: 'Vazba, jejíž zdrojová strana není ve spisovně. Vazby zapisuje jen '
                    . 'projekce spisu a sync pod jeho identitou, takže zdroj má vždy existovat '
                    . '(cílová strana existovat nemusí — to je legitimní).',
                countSql: 'SELECT COUNT(*) FROM case_file_relation r
                    LEFT JOIN case_file c
                      ON (c.court_kod, c.registry_norm, c.senate, c.bc_number, c.year)
                       = (r.src_court_kod, r.src_registry_norm, r.src_senate, r.src_bc_number, r.src_year)
                    WHERE c.id IS NULL',
                samplesSql: "SELECT CONCAT(r.src_court_kod, ' ', r.src_senate, ' ', r.src_registry_norm, ' ',
                        r.src_bc_number, '/', r.src_year, ' (', r.relation_type, ')') AS sample
                    FROM case_file_relation r
                    LEFT JOIN case_file c
                      ON (c.court_kod, c.registry_norm, c.senate, c.bc_number, c.year)
                       = (r.src_court_kod, r.src_registry_norm, r.src_senate, r.src_bc_number, r.src_year)
                    WHERE c.id IS NULL LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'confirmed-binding-without-case',
                category: IntegrityCategory::Discrepancy,
                title: 'Potvrzená vazba jednání bez spisu',
                description: 'Jednání s court_binding = confirmed, ale bez odkazu na spis. '
                    . 'Potvrzení bez spisu nedává smysl.',
                countSql: "SELECT COUNT(*) FROM hearing WHERE court_binding = 'confirmed' AND case_file_id IS NULL",
                samplesSql: "SELECT CONCAT(venue_court_kod, ' ', senate, ' ', registry_norm, ' ',
                        bc_number, '/', year, ' ', hearing_date, ' ', TIME_FORMAT(hearing_time, '%H:%i')) AS sample
                    FROM hearing WHERE court_binding = 'confirmed' AND case_file_id IS NULL LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'hearing-case-identity-mismatch',
                category: IntegrityCategory::Discrepancy,
                title: 'Jednání navázané na spis s jinou značkou',
                description: 'Spis, na který jednání odkazuje, má jinou spisovou značku než jednání. '
                    . 'Soud se lišit smí (potvrzené dožádání), identita nikdy.',
                countSql: 'SELECT COUNT(*) FROM hearing h JOIN case_file c ON c.id = h.case_file_id
                    WHERE (c.registry_norm, c.senate, c.bc_number, c.year)
                       <> (h.registry_norm, h.senate, h.bc_number, h.year)',
                samplesSql: "SELECT CONCAT('hearing #', h.id, ' ', h.senate, ' ', h.registry_norm, ' ',
                        h.bc_number, '/', h.year, ' -> case_file #', c.id, ' ', c.senate, ' ',
                        c.registry_norm, ' ', c.bc_number, '/', c.year) AS sample
                    FROM hearing h JOIN case_file c ON c.id = h.case_file_id
                    WHERE (c.registry_norm, c.senate, c.bc_number, c.year)
                       <> (h.registry_norm, h.senate, h.bc_number, h.year) LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'room-link-mismatch',
                category: IntegrityCategory::Discrepancy,
                title: 'Odkaz jednání na síň nesedí štítkem',
                description: 'Síň, na kterou jednání odkazuje přes room_id, má jiný štítek nebo '
                    . 'patří jinému soudu, než jednání samo tvrdí.',
                countSql: 'SELECT COUNT(*) FROM hearing h JOIN hearing_room r ON r.id = h.room_id
                    WHERE r.court_kod <> h.venue_court_kod OR r.label <> h.room',
                samplesSql: "SELECT CONCAT('hearing #', h.id, ' „', COALESCE(h.room, '-'), '“ (', h.venue_court_kod,
                        ') -> room #', r.id, ' „', r.label, '“ (', r.court_kod, ')') AS sample
                    FROM hearing h JOIN hearing_room r ON r.id = h.room_id
                    WHERE r.court_kod <> h.venue_court_kod OR r.label <> h.room LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'registry-unknown',
                category: IntegrityCategory::Discrepancy,
                title: 'Spis s rejstříkem mimo číselník',
                description: 'Rejstřík spisu ve spisovně není v číselníku rejstříků. U vlastních '
                    . 'spisů má rejstřík vždy sedět — mimo číselník smí být jen cíle vazeb '
                    . '(spisy státního zástupce), a ty se schválně nekontrolují.',
                countSql: 'SELECT COUNT(*) FROM case_file
                    WHERE registry_norm NOT IN (SELECT code_norm FROM registry)',
                samplesSql: "SELECT DISTINCT CONCAT(registry_norm, ' (', court_kod, ')') AS sample
                    FROM case_file
                    WHERE registry_norm NOT IN (SELECT code_norm FROM registry) LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'year-out-of-range',
                category: IntegrityCategory::Discrepancy,
                title: 'Ročník mimo smysluplný rozsah',
                description: 'Spis nebo jednání s ročníkem před rokem 1900 nebo v budoucnosti — '
                    . 'podpis chybného převodu dvojmístného ročníku (CaseYear).',
                countSql: 'SELECT (SELECT COUNT(*) FROM case_file
                        WHERE year < 1900 OR year > YEAR(CURDATE()) + 1)
                     + (SELECT COUNT(*) FROM hearing
                        WHERE year < 1900 OR year > YEAR(CURDATE()) + 1)',
                samplesSql: "SELECT CONCAT('case_file #', id, ' rocnik ', year) AS sample FROM case_file
                        WHERE year < 1900 OR year > YEAR(CURDATE()) + 1
                    UNION ALL
                    SELECT CONCAT('hearing #', id, ' rocnik ', year) FROM hearing
                        WHERE year < 1900 OR year > YEAR(CURDATE()) + 1
                    LIMIT 5",
            ),
            new IntegrityCheck(
                slug: 'journal-recent',
                category: IntegrityCategory::Discrepancy,
                title: 'Žurnál ztrát dat za posledních 30 dní',
                description: 'Záznamy v case_file_journal — destruktivní projekce, odmítnuté '
                    . 'payloady, nečitelná data. Každý výskyt stojí za posouzení Adminerem '
                    . '(before/after snapshoty jsou v žurnálu).',
                countSql: 'SELECT COUNT(*) FROM case_file_journal
                    WHERE occurred_at >= NOW() - INTERVAL 30 DAY',
                samplesSql: "SELECT CONCAT('#', id, ' ', type,
                        CASE WHEN case_file_id IS NULL THEN '' ELSE CONCAT(' (case_file #', case_file_id, ')') END,
                        ' ', occurred_at) AS sample
                    FROM case_file_journal
                    WHERE occurred_at >= NOW() - INTERVAL 30 DAY
                    ORDER BY occurred_at DESC LIMIT 5",
            ),
            // ---- incompleteness: expectedly nonzero, trend matters ----------
            new IntegrityCheck(
                slug: 'room-link-missing',
                category: IntegrityCategory::Incompleteness,
                title: 'Jednání bez odkazu na existující síň',
                description: 'Jednání zná štítek síně, síň je v číselníku, ale room_id je prázdné. '
                    . 'Bezpečně dopárovatelné (HearingRepository::linkRoom).',
                countSql: 'SELECT COUNT(*) FROM hearing h
                    JOIN hearing_room r ON r.court_kod = h.venue_court_kod AND r.label = h.room
                    WHERE h.room_id IS NULL',
                samplesSql: "SELECT CONCAT('hearing #', h.id, ' „', h.room, '“ (', h.venue_court_kod, ')') AS sample
                    FROM hearing h
                    JOIN hearing_room r ON r.court_kod = h.venue_court_kod AND r.label = h.room
                    WHERE h.room_id IS NULL LIMIT 5",
                repair: 'link-rooms',
            ),
            new IntegrityCheck(
                slug: 'hearing-bindable-unbound',
                category: IntegrityCategory::Incompleteness,
                title: 'Navázatelná jednání bez vazby na spis',
                description: 'Spis stejné identity u soudu síně je ve spisovně, ale jednání na něj '
                    . 'neodkazuje. Doplní fáze 1 párování (HearingBindService, venue_guess).',
                countSql: 'SELECT COUNT(*) FROM hearing h
                    JOIN case_file c
                      ON (c.court_kod, c.registry_norm, c.senate, c.bc_number, c.year)
                       = (h.venue_court_kod, h.registry_norm, h.senate, h.bc_number, h.year)
                    WHERE h.case_file_id IS NULL',
                samplesSql: "SELECT CONCAT(h.venue_court_kod, ' ', h.senate, ' ', h.registry_norm, ' ',
                        h.bc_number, '/', h.year, ' ', h.hearing_date) AS sample
                    FROM hearing h
                    JOIN case_file c
                      ON (c.court_kod, c.registry_norm, c.senate, c.bc_number, c.year)
                       = (h.venue_court_kod, h.registry_norm, h.senate, h.bc_number, h.year)
                    WHERE h.case_file_id IS NULL LIMIT 5",
                repair: 'bind-hearings',
            ),
            new IntegrityCheck(
                slug: 'event-detail-missing',
                category: IntegrityCategory::Incompleteness,
                title: 'Události bez staženého detailu',
                description: 'Detail se stahuje lazy při prvním zobrazení, takže nenulový počet je '
                    . 'normální. Hromadné dotažení patří do budoucí fronty, ne do tlačítka.',
                countSql: 'SELECT COUNT(*) FROM case_file_event WHERE detail_fetched_at IS NULL',
            ),
            new IntegrityCheck(
                slug: 'room-unclassified',
                category: IntegrityCategory::Incompleteness,
                title: 'Nezatříděné jednací síně',
                description: 'Síně s kind = unknown čekají na heuristiku nebo ruční kurátorování '
                    . '(zatřídění řídí sílu odhadu domovského soudu).',
                countSql: "SELECT COUNT(*) FROM hearing_room WHERE kind = 'unknown'",
                samplesSql: "SELECT CONCAT('„', label, '“ (', court_kod, ')') AS sample
                    FROM hearing_room WHERE kind = 'unknown' LIMIT 5",
            ),
        ];
    }
}
