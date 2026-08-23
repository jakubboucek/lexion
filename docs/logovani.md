# Aplikační log (`App\Model\Log`, tabulka `log`)

> **Stav: IMPLEMENTOVÁNO (2026-08-22).** Vzešlo z návrhu schváleného po
> třech kolech review (historie rozhodnutí v gitu tohoto souboru, dřív
> `navrh-logovani.md`); zobecňuje a nahrazuje krok 2 (`sync_run`)
> z [navrh-integrita-dat.md](navrh-integrita-dat.md). Vzor: `LogModel`
> projektu skradbuza.cz (inspirace Google Cloud Logging — strukturovaná
> pole místo textového řetězce), rozšířený o stavové záznamy běhů
> s průběžným logem v souboru.

## Zařazení — tři logovací mechanismy s ostrými hranicemi

| Mechanismus | Kam | K čemu |
|---|---|---|
| **Tracy** (`ILogger`) | soubory `web/log/` | výjimky a nízkoúrovňové problémy aplikace |
| **`case_file_journal`** | vlastní tabulka | paměť ztrát dat per spis — forenzní snapshoty, ne provoz |
| **Aplikační log** | tabulka `log` + soubory běhů ve `web/log/` | systémové události a provozní paměť běhů; strojově filtrovatelné, výhled na read-side v UI |

Dva druhy záznamů v jedné tabulce (rozdíl je jen v tom, které sloupce jsou
vyplněné, ne v podstatě — read-side tak má jednu časovou osu):

1. **Instantní záznam** — atomicky vzniklá, neměnná událost. Jeden INSERT
   rovnou ve finálním stavu (`ok`/`failed`).
2. **Záznam běhu** — proces s trváním. Stavy jako u promise:
   `pending` → `ok`/`failed`. Na startu INSERT, na konci jediný UPDATE
   (výsledek, souhrnná data, přeživší soubory); **mezi startem a koncem se
   DB nedotýká** — průběh jde append-only do souborů.

## Tabulka `log` (migrace `2026-08-22-01`)

Sloupce: `resource`+`action` (doména a akce, viz `LogEventKind`), `target`
(cíl akce — id, slug, jméno souboru), `status` (**DB ENUM**
`pending|ok|failed`; rozšíření = `ALTER TABLE … MODIFY` s hodnotou
**přidanou na konec** seznamu, metadata-only), `result` (krátký strojový
výsledek, např. `refused`), `message` (lidský text), `user_id` (**bez FK**
— log nesmí nic omezovat; CLI záznamy uživatele nemají), tři JSON payloady
`data` / `context` / `result_data` („s čím jsem běžel" / „odkud to přišlo"
/ „jak to dopadlo"), `files` (jen běhy: mapa význam → jméno souboru
relativně k log adresáři; **`NULL` hodnota = kanál existoval, ale zůstal
prázdný a soubor se smazal**), `occurred_at` / `finished_at`.

- Časy jsou celosekundové `DATETIME` jako zbytek schématu — driver
  nette/database zlomky sekund stejně nezapisuje; pořadí jistí `id`,
  ms timestampy nesou řádky souborů.
- `status = 'pending'` je jediný příznak „běží, nebo spadl" (viz Detekce
  pádu). **Žádné sekundární indexy** — navrhnou se podle reálného
  filtrování budoucího read-side.
- InnoDB (na rozdíl od skradbuza MyISAM — běhy dělají UPDATE).

## PHP API — `App\Model\Log\`

| Třída | Role |
|---|---|
| `LogService` | fasáda: `log()` / `logRaw()` (instantní; `Pending` odmítají), `buildRunSession()` (příprava běhu — název schválně neříká „run", nic se nespouští). Registrovaná explicitně v `services.neon` s `logDir` = `web/log` |
| `LogEventKind` | interface typované identity: backed enum, `value` = action, `resource()`; per-doména enum vedle ostatních enumů domény (`Sync\SyncLogKind`, `Hearing\HearingLogKind`) |
| `LogStatus` | enum zrcadlící DB ENUM |
| `LogRunBuilder` | registrace souborů typovanými metodami `textFile()` / `jsonlFile()` (typ zapisovače plyne z metody — statická analýza je odliší), `start()` = INSERT + otevření souborů + vrací `LogRun` |
| `LogRun` | `finish(status, result?, message?, resultData?)` — UPDATE + zavření souborů, prázdné smaže a v mapě NULLuje; idempotentní (druhé volání se tiše ignoruje), `Pending` odmítá |
| `LogRunTextFile` / `LogRunJsonlFile` | zapisovače kanálů; **vstup přijímají jen mezi `start()` a `finish()`**, jinak `LogicException` |
| `LogRunChannel` | standardní významy `Out`/`Err`; parametry berou `string\|LogRunChannel`, vlastní názvy (`'problems'`) jsou volné |
| `LogEntry` + `LogRepository` | typová entita a thin write-only repository; finish = patch entita. **JSON sloupce jsou typované přes `Struct\JsonObject`** (hydrator ≥ 0.7, změna 2026-08-23): property vždy drží instanci, prázdný payload ⇔ NULL sloupec, null hodnoty uvnitř dokumentu se zachovávají (mapa `files` na tom závisí). První Struct-typovaná entita projektu — viz [architektura.md](architektura.md), *Konvence entit* |
| `LogContextProvider` | auto-sběr `context` + `user_id`: web `{origin, url, ip, requestId?}` + přihlášený uživatel, CLI `{origin, argv, hostname}` (na `Nette\Security\User` v CLI nesahá — startoval by session) |

```php
// instant
$this->logService->log(SyncLogKind::Export, target: $fileName, data: [...]);

// run
$session = $this->logService->buildRunSession(SyncLogKind::Import, target: $fileName);
$out = $session->textFile(LogRunChannel::Out);
$problems = $session->jsonlFile('problems');
$run = $session->start();
$out->writeLine('processed 1000 records');
$problems->write($problem->toLogData());
$run->finish(LogStatus::Ok, resultData: $report->toLogData());
```

## Soubory běhů

- **Umístění:** `web/log/` (gitignored, deploy-ignored) vedle Tracy logů.
- **Pojmenování:** `run-<YmdHis>-<resource>-<action>-<uniq>-<význam>.log`
  (`.jsonl` pro JSONL); `<uniq>` = 6 hex znaků — jména vznikají v builderu
  před INSERTem, autoritativní je mapa `files` v DB.
- **Zápis:** append přes standardní PHP stream **bez ručního flushování**
  — buffer dopíše každý PHP-level konec (čistý, uncaught výjimka,
  `max_execution_time`, fatal error); o ocásek bufferu se přichází jen
  při tvrdém zabití zvenčí, a proti výpadku by nepomohl ani `fflush()`
  (flushuje do OS cache, ne na disk).
- **Formáty řádků:** text `[2026-08-22 20:51:13.284] text`; JSONL jeden
  objekt na řádek, zapisovač doplňuje klíč `ts` (vyhrává nad klíčem
  volajícího).
- **Prázdný soubor** se při `finish()` smaže a v mapě dostane `NULL`.

## Detekce pádu (záměrně nestavěna)

Ošetřeno je pouze zavření streamů (destruktory zapisovačů — buffery se
dopíšou); **DB se nedotýká**. Nedokončený běh zůstane `pending` se soubory
na disku — rozliší se ručně (stáří + poslední řádky souboru) a nikdy
neblokuje další běh. Volajícím se doporučuje `finish()` ve `finally`, kde
to jde. **Záměr do budoucna** (podle poznatků z provozu): `__destruct`
pojistka zapisující `failed`/`aborted`, shutdown handler pro fatal errory,
read-side heuristika stáří `pending` běhů.

## Konzumenti (stav 2026-08-22)

- **Sync import** (`SyncImportService`) — běh: kanál `out` (hlavička,
  drift číselníků, progress po 1000 záznamech, závěrečné počty; při pádu
  uvnitř záznamu poslední řádek říká, na kterém záznamu), kanál
  `problems` (JSONL, `SyncProblem::toLogData()`), `result_data` = celý
  `SyncImportReport::toLogData()` — počty přežijí HTTP odpověď.
  Nahradilo Tracy kanál `'sync'`; merge služby dostávají zapisovač jako
  parametr metod.
- **Sync export** (`SyncPresenter::actionDownload`) — instantní záznam
  „nabídnut ke stažení" (víc se z odpovědi streamované mimo request
  vědět nedá).
- **CLI tooly jednání** (`bin/infojednani-import.php`,
  `bin/hearing-bind.php`) — běh per spuštění, dry-run je taky běh;
  zrcadlí progress a diagnostiku do `out`, počty do `result_data`.
- Instantní větev je k dispozici i pro další události (login…) —
  zapojení podle potřeby.

## Co se záměrně nedělá (zatím)

Read-side UI v sekci System (čte se Adminerem), sekundární indexy,
retence/rotace souborů i řádků, notifikace o spadlých bězích, „native"
kanál s předáním syrového handle, automatická detekce pádu (viz výše).

## Testy

`web/tests/Model/LogRunFiles.phpt` (zapisovače bez DB: formáty řádků,
`ts`, odmítání vstupu mimo životní cyklus, hlášení prázdného souboru)
a `LogRunLifecycle.phpt` (celý cyklus proti reálné tabulce, bez DB se
skipne; po sobě uklízí).
