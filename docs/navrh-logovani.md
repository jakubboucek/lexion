# Návrh: obecné aplikační logování s podporou dlouhoběžících běhů

> **Stav: NÁVRH (2026-08-22), čeká na schválení.** Zobecnění kroku 2
> (`sync_run`) z [navrh-integrita-dat.md](navrh-integrita-dat.md) — přebírá
> ho celý, tabulka běhů úloh je zároveň odpovědí na tamní otevřenou otázku
> „jen sync, nebo obecné běhy". Vzor: `LogModel` + tabulka `log` z projektu
> skradbuza.cz (inspirace Google Cloud Logging — strukturovaná pole místo
> textového řetězce), zde rozšířený o **stavové záznamy běhů** s průběžným
> logem v souboru. Vstupem byly i požadavky paralelní session (sync import),
> viz [navrh-integrita-dat.md](navrh-integrita-dat.md), krok 2.

## Cíl a zařazení

Tři logovací mechanismy s ostrými hranicemi (žádný nenahrazuje jiný):

| Mechanismus | Kam | K čemu |
|---|---|---|
| **Tracy** (`ILogger`) | soubory `web/log/` | výjimky a nízkoúrovňové problémy aplikace; zůstává beze změny |
| **`case_file_journal`** | vlastní tabulka | paměť ztrát dat per spis — forenzní snapshoty, ne provoz |
| **Aplikační log (tento návrh)** | tabulka `log` + soubory běhů | systémové události a provozní paměť běhů; strojově filtrovatelné, od počátku s výhledem na read-side v UI |

Do aplikačního logu patří dva druhy záznamů:

1. **Instantní záznam** — atomicky vzniklá, neměnná událost („import odmítl
   soubor", „číselník se liší"). Zapíše se jedním INSERTem a už se nemění.
2. **Záznam běhu** — proces s trváním (sync import, budoucí opravné akce,
   kontroly integrity, CLI tooly). Stavy analogicky k promise:
   `pending` → `ok` / `failed`. Na startu INSERT (kontext, kdo, kdy),
   na konci jediný UPDATE (výsledek, souhrnná data). **Mezi startem
   a koncem se DB nedotýká** — průběh jde append-only do souborů ve
   `web/log/` s flushem po každém řádku, takže po pádu poslední řádek
   souboru identifikuje zpracovávaný záznam.

## Proveditelnost — ověřená fakta

- **`web/log/` existuje na produkci a je zapisovatelný** — Tracy do něj
  loguje (`Bootstrap::enableTracy($rootDir . '/log')`); deploy ho ignoruje
  (`.deployment.php`), soubory běhů tedy deploy nesmaže.
- **JSON sloupce** už v projektu jedou (`case_file_journal`, MariaDB 10.5:
  `JSON` = `LONGTEXT` + validační CHECK) — žádná nová technologie.
- **CLI i HTTP**: služba v `web/app/Model/` je dostupná z presenterů i z CLI
  (`(new Bootstrap)->bootConsoleApplication()` — vzor datových migrací
  a `bin/` toolů). Na produkci bude k dispozici (deploy nahrává `web/`).
- **Souběh**: každý běh má vlastní id a vlastní soubory — žádné zámky,
  nedokončený běh neblokuje další (vzájemné vyloučení procesů je věc
  volajícího, ne logu).
- **Adopce v syncu je levná**: dnešní `ILogger::log(..., 'sync')` volání
  v `SyncImportService`/`CaseFileMergeService`/`HearingMergeService` jsou
  4 místa; `SyncProblem::logLine()` se nahradí strukturovaným JSONL zápisem.

## Datový model — tabulka `log`

**Jedna tabulka pro oba druhy záznamů** (instantní i běhy). Důvody: zadání
výslovně chce obecný log místo tabulky per funkce; read-side chce jednu
časovou osu „co se v systému dělo"; rozdíl mezi druhy je jen v tom, které
sloupce jsou vyplněné (`status`/`finished_at`/`files`), ne v podstatě záznamu.

```sql
CREATE TABLE `log`
(
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `resource`    VARCHAR(30)   NOT NULL,             -- domain of the event ('sync', 'hearing', ...)
    `action`      VARCHAR(100)  NOT NULL,             -- action within the resource ('import', 'bind', ...)
    `target`      VARCHAR(100)  NULL DEFAULT NULL,    -- acted-on entity (id, slug, filename...)
    `status`      VARCHAR(10)   NOT NULL,             -- pending | ok | failed
    `result`      VARCHAR(100)  NULL DEFAULT NULL,    -- short machine-readable outcome ('aborted', ...)
    `message`     VARCHAR(1000) NULL DEFAULT NULL,    -- human-readable text payload
    `user_id`     INT UNSIGNED  NULL DEFAULT NULL,    -- initiating user; NULL for CLI/system
    `data`        JSON          NULL DEFAULT NULL,    -- caller-provided payload (start parameters)
    `context`     JSON          NULL DEFAULT NULL,    -- auto-collected environment (origin, url/argv, ip, request id)
    `result_data` JSON          NULL DEFAULT NULL,    -- caller-provided outcome payload (e.g. import report)
    `files`       JSON          NULL DEFAULT NULL,    -- runs only: channel => format map; finish keeps surviving files
    `occurred_at` DATETIME(3)   NOT NULL,             -- event time / run start (app-filled, app TZ)
    `finished_at` DATETIME(3)   NULL DEFAULT NULL,    -- runs only: end of run
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_log_status` CHECK (`status` IN ('pending', 'ok', 'failed'))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
```

Poznámky k rozhodnutím:

- **`resource` + `action`** — převzato ze skradbuza; v PHP typované enumy
  (viz níže), volné stringy jen únikovou cestou.
- **Tři JSON sloupce, ne jeden.** GCP model oddělení strukturovaných polí od
  payloadu, dotažený pro naše čtení: `data` = co předal volající při vzniku
  („s čím jsem běžel"), `context` = co si služba zjistila sama („odkud to
  přišlo"), `result_data` = co předal volající na konci („jak to dopadlo" —
  u importu celý report, aby přežil HTTP odpověď). Textový payload je zvlášť
  (`message`), takže se nemíchá do JSON struktur.
- **`status` jako stav promisy**, ne boolean `success` — instantní záznam se
  založí rovnou v `ok`/`failed`, běh v `pending`. `status = 'pending'` je
  jediný příznak „běží, nebo spadl"; instantní záznamy `pending` nikdy nemají.
- **`occurred_at`/`finished_at`** — pojmenování konzistentní s žurnálem
  (`occurred_at`), plní aplikace (app TZ Europe/Prague, jako všude v modelu).
  Milisekundová přesnost kvůli řazení a trvání běhů; pořadí jistí `id`.
- **`user_id` bez FK** — log je append-only evidence a nesmí nic
  omezovat ani být omezován; navíc CLI/systémové záznamy uživatele nemají.
  (Jiná volba než u žurnálu, kde FK záměrně blokuje smazání spisu — tady
  chráníme nezávislost logu, ne referenci.)
- **InnoDB** (odchylka od skradbuza MyISAM): běhy dělají UPDATE na konci,
  MyISAM by zamykal celou tabulku; crash-safety je u provozní paměti
  žádoucí; celý projekt je InnoDB.
- **Engine výkonu se neřeší předem**: žádné sekundární indexy —
  navrhnou se v další iteraci podle reálného filtrování v UI (zadání).
- Migrace: `migrations/structures/<datum>-XX-create-log-table.sql`.

## PHP API — `App\Model\Log\`

Nový doménový modul. Názvy se vyhýbají kolizi s `Tracy\ILogger`:

| Třída | Role |
|---|---|
| `LogService` | fasáda: `log()` (instantní), `start()` (běh); jediná vstupní brána |
| `LogEventKind` (interface) | typované `resource`+`action`: backed enum, `value` = action, `resource()` — vzor skradbuza; per-doména enum (např. `Sync\SyncLogKind`) žije vedle ostatních enumů domény |
| `LogStatus` (enum) | `Pending`/`Ok`/`Failed` — DB drží CHECK, enum je tedy dle konvence na místě |
| `LogRun` | handle běhu vrácený ze `start()`: přístup k souborům, `finish()` |
| `LogRunFile` / `LogRunJsonlFile` | zapisovače kanálů (viz Soubory) |
| `LogFileFormat` (enum) | `Text` / `Jsonl` / `Native` |
| `LogEntry` (entita) + `LogRepository` | DB vrstva dle konvence typových entit; finish = patch entita; základ budoucího read-side |
| `LogContextProvider` | auto-sběr `context` + `user_id`: HTTP (url, ip, request id, přihlášený uživatel) vs. CLI (`argv`, hostname); místo dědičnosti `FrontendLogModel` ze skradbuza — jedna služba, kontext se skládá injektovaným providerem a v CLI degraduje tiše |

Užití:

```php
// instant record
$this->logService->log(
    SyncLogKind::CodelistDiff,
    status: LogStatus::Ok,
    target: 'court',
    message: 'Codelist court differs from the file in 3 row(s)',
    data: ['differences' => 3],
);

// run
$run = $this->logService->start(SyncLogKind::Import, data: [
    'dataset' => $dataset->value,
    'file' => $originalName,
], files: [
    'run' => LogFileFormat::Text,       // progress narrative (STDOUT analogy)
    'problems' => LogFileFormat::Jsonl, // structured skips (STDERR analogy)
]);

$run->file('run')->writeLine('case files: 1500 processed');
$run->file('problems')->write($problem->toLogData());

$run->finish(LogStatus::Ok, resultData: $report->toLogData());
```

Požadavky na soubory (kanály + formát) určuje volající při `start()` —
zapíšou se do sloupce `files`; `finish()` tam nechá jen soubory, které
skutečně přežily (prázdné se smažou).

## Soubory běhů

- **Umístění:** `web/log/` (gitignored, deploy-ignored) vedle Tracy logů.
- **Pojmenování:** `run-<YmdHis>-<resource>-<action>-<id>-<kanál>.log`
  (`.jsonl` pro JSONL) — odvoditelné z DB řádku (čas, typ, id, klíče
  `files`), takže se do DB neukládají cesty; časový prefix řadí adresář
  chronologicky. Soubory se otevírají až po INSERTu (id je součást jména).
- **Zápis:** append, **`fflush()` po každém řádku** — žádné bufferování;
  po tvrdém pádu je poslední řádek poslední dokončená operace.
- **Text kanál:** `writeLine(string)` → `[2026-08-22 15:30:01.123] text`
  — greppovatelné. **JSONL kanál:** `write(array|JsonSerializable)` →
  jeden JSON objekt na řádek, služba doplní klíč `ts`. **Native kanál:**
  služba soubor jen vytvoří a předá syrový stream (`resource`) — pro
  případy, kdy si zápis řídí volající (pipe externího procesu apod.);
  služba ho na konci zavře a prázdný smaže.
- **Prázdný soubor** se při `finish()` smaže a vyřadí z `files` — čistý
  běh bez problémů po sobě nenechá smetí.
- **Retence** souborů i DB řádků je záměrně mimo rozsah (zadání bod 8) —
  doplní se, až bude read-side a reálné objemy.

## Detekce pádu

Vrstveně, od nejlevnějšího:

1. **`finished_at IS NULL` + `status = 'pending'`** = běží, nebo spadl.
2. **Shutdown handler:** `LogService` si při prvním `start()` zaregistruje
   `register_shutdown_function`; neukončené běhy při shutdownu dostanou
   do textových kanálů řádek `ABORTED (shutdown)` a DB UPDATE
   `status = 'failed'`, `result = 'aborted'`, `finished_at = now`.
   Pokrývá fatal errory a normální konce; v PHP shutdown handlery po
   fatalu běží.
3. **Co shutdown nepokryje** (SIGKILL, OOM kill, segfault, výpadek):
   rozliší heuristika na read-side — `pending` + stáří + **mtime souborů
   běhu** (flush po řádku dělá z mtime laciný heartbeat zadarmo). Teď stačí,
   že data vznikají; vyhodnocení bude součástí UI výpisu běhů.
4. CLI tooly mohou volitelně přidat `pcntl_signal` pro SIGINT/SIGTERM
   (Ctrl+C shutdown handlery nespouští) — nepovinné, per tool.

`finish()` je idempotentní pojistka: druhé volání (např. handler po ručním
finish) se tiše ignoruje.

## Adopce — první konzumenti

1. **Sync import** (`System\Sync` presenter → `SyncImportService`): celý
   import = jeden běh. Kanál `run` (text, průběžné fáze/počty), kanál
   `problems` (JSONL — `SyncProblem` se serializuje strukturovaně místo
   dnešního `logLine()`), `result_data` = `SyncImportReport` (report tak
   přeživá HTTP odpověď — požadavek paralelní session). Konstruktorové
   `Tracy\ILogger` závislosti v `SyncImportService`/`CaseFileMergeService`/
   `HearingMergeService` se odstraní; zapisovač poteče jako parametr metod
   (merge služby už dnes orchestruje import, jen jim předá handle).
   Kanál `'sync'` v Tracy tím zaniká.
2. **Sync export** — instantní záznam (kdo, kdy, jaká sada) při `download`.
3. **CLI tooly jednání** (`bin/infojednani-import.php`, `bin/hearing-bind.php`)
   — běh per spuštění; nezávislé na plánovaném přesunu logiky do služeb
   (krok 3 návrhu integrity), handle se předává stejně tam i tam.
4. **Budoucí:** opravné akce (dry-run i apply — dry-run je taky běh!),
   kontroly integrity, fronta scanů. Instantní větev je k dispozici i pro
   bezpečnostní události (login), ale jejich zapojení není součástí této vlny.

## Co se teď záměrně nedělá

- **Read-side UI** (výpis běhů v sekci System) — až data budou vznikat;
  `LogRepository` vznikne rovnou, ale jen pro potřeby zápisu.
- **Indexy** — podle budoucího filtrování (zadání).
- **Retence/rotace** souborů a řádků.
- **Notifikace** o spadlých bězích — monitoring zatím neexistuje.

## Postup realizace (po schválení)

1. Migrace `create-log-table.sql`.
2. Modul `App\Model\Log\` (service, entity, repository, handle, writery,
   context provider) + registrace v `common.neon`.
3. Testy čisté logiky (formát řádků, mazání prázdných souborů, idempotence
   finish) přes nette/tester.
4. Adopce v sync importu + exportu (náhrada `ILogger 'sync'`).
5. Adopce v CLI toolech jednání.
6. Dokumentace: tento soubor přepsat na popis stavu (nebo přesunout do
   architektura.md) + aktualizace CLAUDE.md a navrh-integrita-dat.md.
