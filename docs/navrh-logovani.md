# Návrh: obecné aplikační logování s podporou dlouhoběžících běhů

> **Stav: NÁVRH (2026-08-22), po prvním review — zapracovány úpravy:
> `status` jako DB ENUM, builder API pro běhy, explicitní cesty souborů
> v DB, zápis bez ručního flushování.** Zobecnění kroku 2 (`sync_run`)
> z [navrh-integrita-dat.md](navrh-integrita-dat.md) — přebírá ho celý,
> tabulka běhů úloh je zároveň odpovědí na tamní otevřenou otázku „jen
> sync, nebo obecné běhy". Vzor: `LogModel` + tabulka `log` z projektu
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
   `pending` → `ok` / `failed`. Na startu INSERT (kontext, kdo, kdy,
   soubory), na konci jediný UPDATE (výsledek, souhrnná data). **Mezi
   startem a koncem se DB nedotýká** — průběh jde append-only do souborů
   ve `web/log/`.

## Proveditelnost — ověřená fakta

- **`web/log/` existuje na produkci a je zapisovatelný** — Tracy do něj
  loguje (`Bootstrap::enableTracy($rootDir . '/log')`); deploy ho ignoruje
  (`.deployment.php`), soubory běhů tedy deploy nesmaže.
- **JSON sloupce** už v projektu jedou (`case_file_journal`, MariaDB 10.5:
  `JSON` = `LONGTEXT` + validační CHECK) — žádná nová technologie.
- **CLI i HTTP**: služba v `web/app/Model/` je dostupná z presenterů i z CLI
  (`(new Bootstrap)->bootConsoleApplication()` — vzor datových migrací
  a `bin/` toolů). Na produkci bude k dispozici (deploy nahrává `web/`).
- **Souběh**: každý běh má vlastní soubory s unikátními jmény — žádné
  zámky, nedokončený běh neblokuje další (vzájemné vyloučení procesů je
  věc volajícího, ne logu).
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
    `status`      ENUM ('pending', 'ok', 'failed') NOT NULL,
    `result`      VARCHAR(100)  NULL DEFAULT NULL,    -- short machine-readable outcome ('aborted', ...)
    `message`     VARCHAR(1000) NULL DEFAULT NULL,    -- human-readable text payload
    `user_id`     INT UNSIGNED  NULL DEFAULT NULL,    -- initiating user; NULL for CLI/system
    `data`        JSON          NULL DEFAULT NULL,    -- caller-provided payload (start parameters)
    `context`     JSON          NULL DEFAULT NULL,    -- auto-collected environment (origin, url/argv, ip, request id)
    `result_data` JSON          NULL DEFAULT NULL,    -- caller-provided outcome payload (e.g. import report)
    `files`       JSON          NULL DEFAULT NULL,    -- runs only: meaning => filename map, see below
    `occurred_at` DATETIME(3)   NOT NULL,             -- event time / run start (app-filled, app TZ)
    `finished_at` DATETIME(3)   NULL DEFAULT NULL,    -- runs only: end of run
    PRIMARY KEY (`id`)
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
- **`status` jako DB ENUM** (rozhodnutí po review) — nesmysl do sloupce
  nepropadne ani ručním zásahem. Stav je promise-style: instantní záznam
  se založí rovnou v `ok`/`failed`, běh v `pending`; `status = 'pending'`
  je jediný příznak „běží, nebo spadl". Rozšíření množiny = `ALTER TABLE
  … MODIFY` s hodnotou **přidanou na konec seznamu** (metadata-only,
  instantní); v PHP zrcadlí `LogStatus` enum.
- **`files` = mapa význam → jméno souboru** relativně k log adresáři
  (`{"out": "run-…-out.log", "problems": "run-…-problems.jsonl"}`).
  Cesty jsou v DB **explicitně** (rozhodnutí po review — žádné odvozování
  jmen z řádku). Když je soubor při `finish()` prázdný, smaže se a hodnota
  v mapě se přepíše na `NULL` — klíč zůstává, takže je zřejmé, že kanál
  existoval, ale nic nezapsal.
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
- **Žádné sekundární indexy** — navrhnou se v další iteraci podle reálného
  filtrování v UI (zadání).
- Migrace: `migrations/structures/<datum>-XX-create-log-table.sql`.

## PHP API — `App\Model\Log\`

Nový doménový modul. Názvy se vyhýbají kolizi s `Tracy\ILogger`:

| Třída | Role |
|---|---|
| `LogService` | fasáda: `log()` (instantní záznam), `run()` (vrací builder běhu) |
| `LogEventKind` (interface) | typované `resource`+`action`: backed enum, `value` = action, `resource()` — vzor skradbuza; per-doména enum (např. `Sync\SyncLogKind`) žije vedle ostatních enumů domény |
| `LogStatus` (enum) | `Pending`/`Ok`/`Failed` — zrcadlí DB ENUM |
| `LogRunBuilder` | staví běh: základní info + registrace souborů, `start()` provede INSERT, otevře soubory a vrátí `LogRun` |
| `LogRun` | handle běžícího běhu: `finish()`; `__destruct` pojistka (viz Detekce pádu) |
| `LogRunTextFile` / `LogRunJsonlFile` | typově odlišené zapisovače kanálů (viz Soubory) |
| `LogRunChannel` (enum) | standardní významy souborů: `Out` (`'out'`), `Err` (`'err'`); parametry berou `string\|LogRunChannel`, aplikace může použít vlastní název pro specifické struktury |
| `LogEntry` (entita) + `LogRepository` | DB vrstva dle konvence typových entit; finish = patch entita; základ budoucího read-side |
| `LogContextProvider` | auto-sběr `context` + `user_id`: HTTP (url, ip, request id, přihlášený uživatel) vs. CLI (`argv`, hostname); místo dědičnosti `FrontendLogModel` ze skradbuza — jedna služba, kontext se skládá injektovaným providerem a v CLI degraduje tiše |

**Běhy staví builder** (rozhodnutí po review — místo `start()` s polem
kanálů): v prvním volání základní informace, pak typované metody pro
získání souborů — typ zapisovače plyne z volané metody, takže je odlišitelný
statickou analýzou — a nakonec `start()`, který zapíše DB záznam a vrátí
handle na ukončení:

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
$builder = $this->logService->run(SyncLogKind::Import)
    ->target($originalName)
    ->data(['dataset' => $dataset->value]);
$out = $builder->textFile(LogRunChannel::Out);   // LogRunTextFile
$problems = $builder->jsonlFile('problems');     // LogRunJsonlFile — custom meaning
$run = $builder->start();                        // INSERT + opens files

$out->writeLine('case files: 1500 processed');
$problems->write($problem->toLogData());

$run->finish(LogStatus::Ok, resultData: $report->toLogData());
```

Kontrakt builderu: zapisovače se vracejí hned (kvůli typům a předání do
závislostí), ale zápis před `start()` je `LogicException` — soubor nesmí
existovat dřív než jeho DB záznam. `finish()` je idempotentní pojistka:
druhé volání (např. z destruktoru po ručním finish) se tiše ignoruje.

## Soubory běhů

- **Umístění:** `web/log/` (gitignored, deploy-ignored) vedle Tracy logů.
- **Pojmenování:** `run-<YmdHis>-<resource>-<action>-<uniq>-<význam>.log`
  (`.jsonl` pro JSONL); `<uniq>` = krátký náhodný suffix (jména vznikají
  v builderu před INSERTem, DB id v nich není potřeba — autoritativní
  je mapa `files` v DB). Časový prefix řadí adresář chronologicky.
- **Zápis:** append přes standardní PHP stream, **bez ručního flushování**
  (rozhodnutí po review). Buffer řeší PHP: při čistém konci, uncaught
  výjimce, `max_execution_time` i fatal erroru se streamy při shutdownu
  zavřou a buffery dopíšou — o obsah se přichází jen při tvrdém zabití
  procesu zvenčí (SIGKILL, OOM killer, výpadek), a to maximálně o ocásek
  bufferu; proti výpadku by ostatně nepomohl ani `fflush()` (flushuje do
  OS cache, ne na disk — to umí až `fsync`). Na tyhle scénáře se
  nedimenzuje.
- **Text kanál:** `writeLine(string)` → `[2026-08-22 15:30:01.123] text`
  — greppovatelné. **JSONL kanál:** `write(array|JsonSerializable)` →
  jeden JSON objekt na řádek, služba doplní klíč `ts`. (Původně zvažovaný
  „native" kanál s předáním syrového streamu se teď nestaví — builder ho
  může dostat jako další metodu, až bude konzument.)
- **Prázdný soubor** se při `finish()` smaže a v mapě `files` dostane
  `NULL` — čistý běh bez problémů po sobě nenechá smetí, ale záznam
  o existenci kanálu zůstane.
- **Retence** souborů i DB řádků je záměrně mimo rozsah (zadání bod 8) —
  doplní se, až bude read-side a reálné objemy.

## Detekce pádu

Dimenzováno na pády na úrovni PHP (výjimky, fatal errory), ne na apokalypsu
(rozhodnutí po review). Vrstveně, od nejlevnějšího:

1. **Volající:** `finish()` ve `finally`, kde to struktura kódu umožňuje.
2. **`LogRun::__destruct`:** neukončený běh při úklidu objektu dostane
   `status = 'failed'`, `result = 'aborted'`, `finished_at = now` a do
   textových kanálů řádek `ABORTED` — pokrývá uncaught výjimky a konec
   requestu/procesu bez `finish()`.
3. **Shutdown handler** (`register_shutdown_function`, registruje
   `LogService` při prvním `start()`): záchrana pro fatal errory, kde PHP
   destruktory nevolá. Dělá totéž co destruktor.
4. **Co zbyde** (SIGKILL, OOM kill, výpadek): řádek zůstane `pending` —
   read-side ho rozliší stářím (`pending` + `occurred_at` dávno). Vědomě
   akceptovaná mezera; `pending` řádek nikdy neblokuje spuštění dalšího
   běhu.

## Adopce — první konzumenti

1. **Sync import** (`System\Sync` presenter → `SyncImportService`): celý
   import = jeden běh. Kanál `out` (text, průběžné fáze/počty), kanál
   `problems` (JSONL — `SyncProblem` se serializuje strukturovaně místo
   dnešního `logLine()`), `result_data` = `SyncImportReport` (report tak
   přežije HTTP odpověď — požadavek paralelní session). Konstruktorové
   `Tracy\ILogger` závislosti v `SyncImportService`/`CaseFileMergeService`/
   `HearingMergeService` se odstraní; zapisovače potečou jako parametry
   metod (merge služby už dnes orchestruje import, jen jim předá typované
   zapisovače). Kanál `'sync'` v Tracy tím zaniká.
2. **Sync export** — instantní záznam (kdo, kdy, jaká sada) při `download`.
3. **CLI tooly jednání** (`bin/infojednani-import.php`, `bin/hearing-bind.php`)
   — běh per spuštění; nezávislé na plánovaném přesunu logiky do služeb
   (krok 3 návrhu integrity), zapisovače se předávají stejně tam i tam.
4. **Budoucí:** opravné akce (dry-run i apply — dry-run je taky běh!),
   kontroly integrity, fronta scanů. Instantní větev je k dispozici i pro
   bezpečnostní události (login), ale jejich zapojení není součástí této vlny.

## Co se teď záměrně nedělá

- **Read-side UI** (výpis běhů v sekci System) — až data budou vznikat;
  `LogRepository` vznikne rovnou, ale jen pro potřeby zápisu.
- **Indexy** — podle budoucího filtrování (zadání).
- **Retence/rotace** souborů a řádků.
- **Notifikace** o spadlých bězích — monitoring zatím neexistuje.
- **Native kanál** (předání syrového file handle) — až bude konzument.

## Postup realizace (po schválení)

1. Migrace `create-log-table.sql`.
2. Modul `App\Model\Log\` (service, entity, repository, builder, handle,
   zapisovače, context provider) + registrace v `common.neon`.
3. Testy čisté logiky (formát řádků, mazání prázdných souborů + NULL
   v mapě, idempotence finish, LogicException při zápisu před start)
   přes nette/tester.
4. Adopce v sync importu + exportu (náhrada `ILogger 'sync'`).
5. Adopce v CLI toolech jednání.
6. Dokumentace: tento soubor přepsat na popis stavu (nebo přesunout do
   architektura.md) + aktualizace CLAUDE.md a navrh-integrita-dat.md.
