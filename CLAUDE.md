# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> Poznámka k jazyku: dokumentace v tomto projektu se píše česky, komunikace s uživatelem probíhá česky. Anglicky zůstává pouze vše code-related (kód, komentáře v kódu, názvy proměnných/funkcí, commit messages, PR popisy). Mezi „kód“ patří i **SQL migrace** ve `migrations/` — samotné DDL i komentáře v nich (`-- ...`) jsou anglicky; česky se píšou jen názvy/popisy mimo kód.

**Lexion** — scraper/checker nad českým infoSoudem: sledování soudních řízení
a notifikace o změnách. Název je slovní hříčka nad doménou `ion.cz`; produkce
poběží na **lex.ion.cz**. Repo je na GitHubu: **github.com/jakubboucek/lexion**

(remote `origin`; issues se evidují tamtéž přes `gh issue …`). Kořenový adresář repa se jmenuje
`lexion` (dřív historicky `infosoud-checker` — přejmenováno 4. 8. 2026, starý název už nikde
nefiguruje). Kompletní zadání:
[docs/zadani.md](docs/zadani.md). Popis existující architektury (moduly, typové
entity, cache, číselníky, pravidla načítání): [docs/architektura.md](docs/architektura.md).

Cíle, plány a designové úvahy budoucího rozvoje (monitoring, fronta scanů, S3,
notifikace, Tool 2…): [docs/roadmap.md](docs/roadmap.md) — plány patří tam,
popis stavu do architektury/sem. Evidence technologického dluhu z auditu kódu
(odbavuje se postupně, položky odškrtávat): [docs/tech-debt.md](docs/tech-debt.md).
**Převod na typové entity** je dokončen (2026-08-05) — konvence entit,
repositories, patch sémantika a pasti žijí v
[docs/architektura.md](docs/architektura.md), sekce *Typové entity a repositories*.

Klíčové zjištění: nový infosoud (infosoud.gov.cz) má veřejné JSON API bez
autentizace — HTML scraping není potřeba. Popis endpointů, formát requestů,
quirky (nenalezeno jako HTTP 400) a deep-linky: [docs/infosoud-api.md](docs/infosoud-api.md).
Obdobné veřejné JSON API má i **infoDeska** (infodeska.gov.cz — úřední desky soudů,
zdroj rozvrhů práce v PDF; filtry SPA nejdou přednastavit z URL, náhradou je přímo API):
[docs/infodeska-api.md](docs/infodeska-api.md).
Analýza detailu událostí, (ne)robustnosti `poradi` a návrh rozpadu JSON cache
do tabulek `case_file_event`/`case_file_relation`: [docs/analyza-udalosti.md](docs/analyza-udalosti.md).
**Synchronizace dat mezi prostředími** (jednosměrný aditivní merge přes JSONL soubor,
sekce System; tři sady — spisy, síně, jednání; princip, formát, pasti a co se
nepřenáší): [docs/sync.md](docs/sync.md). Návrh navazujících kontrol integrity,
oprav a přesunu logiky jednání z `bin/` do služeb (společný podklad dvou
sessions, kroky 1–4 zatím neimplementované):
[docs/navrh-integrita-dat.md](docs/navrh-integrita-dat.md).
**Aplikační log** (2026-08-22, implementováno): tabulka `log` + soubory běhů
ve `web/log/` — instantní záznamy a stavové běhy pending/ok/failed
(`App\Model\Log`: `LogService::log()` / `buildRunSession()` → typované
zapisovače text/JSONL, `finish()` s result payloadem; detekce pádu záměrně
nestavěná, nedokončený běh = `pending`). Nahrazuje krok 2 `sync_run` z návrhu
integrity i Tracy kanál `'sync'`; zapojeno: sync import (běh) + export
(instantní), CLI tooly jednání. Read-side zatím není (čte se Adminerem). Viz
[docs/logovani.md](docs/logovani.md).
**Žurnál ztrát dat `case_file_journal`** (2026-08-22): anomálie, při kterých
se destruuje či zahazuje — destruktivní běh projekce, odmítnutý detail
události, odmítnutá odpověď spisu, nečitelný payload — s úplnými before/after
JSON snapshoty stavu spisu (Hydrator `Format\Json`, doménové názvy polí,
obnova zatím neimplementovaná); projekce je kvůli tomu rozdělená na
`plan()`/`apply()` (plán = čistý diff, dry-run zadarmo, vazby se diffují
místo rebuild). Bez konzumenta — čte se Adminerem. Viz
[docs/architektura.md](docs/architektura.md), sekce *Žurnál ztrát dat*.
**Derivovaná data místo čtení JSON za běhu** (2026-08-26): raw JSON sloupce jsou jen
pro zápis, kontroly a analýzy — **zobrazení stránky je nedekóduje**. Co UI potřebuje, se
materializuje při zápisu do sloupců `case_file.subject`/`status`/`status_date`/`intake_kind`
a `case_file_event.hearing_at`/`hearing_room`/`hearing_type` (překlad payload → patch entity
vlastní statická `CaseFile\CaseSummaryExtraction`; zapisuje se tam, kde se zapisuje zdroj).
Tím zanikl syntetický klíč `firstEventDetail` v `infosoud_json` i `InfosoudCaseOverview`.
Výjimky, kde se JSON čte dál: **atributy NS** (`CaseSummaryService`) a **stránka detailu
události**. Viz [docs/architektura.md](docs/architektura.md), sekce *Derivovaná data*.
Číselníkové paradigma — cache číselníků (`court`/`registry`/`court_prefix`/
`relation_type`: serializovaný snapshot entit s lookup mapami přes nette/caching,
`Codelist\CodelistCache`; repositories beze změny API, 0 SQL na číselníky při teplé
cache; **ruční číselníková migrace bez deploye = smazat cache**, viz architektura):
odůvodnění v [docs/analyza-ciselniky.md](docs/analyza-ciselniky.md).

Stav: hotový skeleton (public část, login-wall, modul Panel, DB s tabulkou `user`)
+ **tool parser spisovky** (na úvodní stránce — parsování, validace s našeptáváním,
detekce soudu, deep-link na infosoud), **tool detail spisu** (`/spis/<soud>/<slug>` — cache-first,
max 2 requesty na justici, timeline událostí, související řízení jen jako odkazy),
číselníky soudů/rejstříků v DB a **spisovna** (tabulka `case_file`, JSON
sloupce per zdroj; ~13 tis. řízení pochází z jednorázového importu ISIR výpisů — importní
tool byl po splnění účelu odstraněn, plnění dnes: `bin/infosoud-fetch.php` s `InfosoudClient`
a samotný web) + **projekční tabulky událostí a vazeb**
(`case_file_event`/`case_file_relation` + číselník `relation_type`; staví je
`CaseFileProjectionService` z raw JSON při syncu), **detail události** (viz presenter
`Spis`) a **oblíbené spisy** (tabulky `favorite`/`favorite_group`, hvězdička s modaly na
detailu spisu, přehled se skupinami a ručním řazením na Panel Dashboardu — viz sekce
*Oblíbené spisy*) a **evidence jednání z infoJednání** (tabulky `hearing`/`hearing_observation`
+ číselník síní `hearing_room`; sken `bin/infojednani-scan.php` → import
`bin/infojednani-import.php`; ~36 tis. jednání za 30denní okno — viz
[docs/infojednani-api.md](docs/infojednani-api.md)). Monitoring, fronta a notifikace zatím
neexistují. Vazbu jednání na `case_file` páruje `bin/hearing-bind.php` (tenká obálka nad
`HearingBindService`; logika jednání žije od 2026-08-23 ve službách
`App\Model\Hearing` — `HearingScanImportService`, `HearingBindService`,
sdílená merge pravidla `HearingMergeRules` — takže je nasazená i na produkci)
ve dvou fázích:
odhad podle soudu síně (`court_binding = venue_guess`) a potvrzení proti `JED_*` detailům
událostí z infosoudu (`confirmed` — umí i převázat na řízení u jiného soudu, „infoSoud
wins“); stav `refuted` zatím neexistuje.

**Tři formy rejstříku** (číselník `registry`: sloupce `code`/`code_norm`/`slug`):
**display** „P a Nc“ (uživatelské výstupy, skutečná značka) → **norm** „P A NC“
(`mb_strtoupper`, infoSoud API/URL) → **slug** „panc“ (naše URL, `Spisovka::slugifyRegistry`
= lowercase + bez diakritiky/mezer). Směr display→norm/slug je deterministická transformace
stringu, opačně je ztrátový (`nscr`→`NSČR`), proto reverzní lookup jede přes číselník
(`RegistryRepository::displayFromSlug`/`displayFromNorm`). Konzistenci číselníku s PHP
transformací hlídá test `web/tests/Model/RegistryCodelistConsistency.phpt`. Hodnotový objekt
**`Spisovka`** nese display formu, `registryNorm()`/`toSlug()` odvozuje deterministicky
(bez závislosti); kanonickou display `Spisovka` z DB/číselníku staví `SpisovkaFactory`.

**Identita spisu = pětice (soud, rejstřík, senát, číslo, ročník)** — každý senát má
vlastní číselnou řadu (ověřeno: OS Trutnov má odlišná řízení 6/7/9/30 C 1/2023)
a stejná SZ existuje i na více soudech. Nikdy nepovažuj SZ za unikátní bez soudu
a senátu. **Pozor na pořadí:** pětice identity v kódu má rejstřík před senátem,
ale **lidský zápis značky má senát PŘED rejstříkem** — v soudnictví se senát
označuje spojením „[číslo senátu] [rejstřík]“ (např. **„35 C“**) a celá značka
je **„senát rejstřík číslo/ročník“** („35 C 138/2026“, viz `Spisovka::format()`).
Všechny uživatelské výstupy i CLI vstupy toolů drží tenhle značkový řád
(senát-rejstřík), ne pořadí identity; identitní pořadí zůstává jen interní
konvence datového modelu.

**Ročník je interně vždy čtyřmístný** (1961, 2024) — v `Spisovka`, ve všech sloupcích DB
i v našich URL (slug je na 4 číslice striktní, dvoumístné URL se odmítají). Justice ale
u **stále živých spisů z 20. století** používá dvoumístný ročník („0 P 480/**61**“), takže
na hranicích se převádí přes `App\Model\Spisovka\CaseYear`: `fromUserInput()` (pivot dle
aktuálního roku, odmítá budoucnost), `fromUpstream()` (data z API — dvojčíslí **vždy** 19xx,
bez pivotu), `forApi()` (strip na dvojčíslí) a `forDisplay()` (tvar, jak píše soud).
**Raw JSON sloupce zůstávají nedotčené** — každé čtení `rocnik` z nich musí projít
`fromUpstream()`. Detaily a past „2098 vrátí spis z 1998“: [docs/infosoud-api.md](docs/infosoud-api.md).

## Terminologie a pojmenování (závazné konvence)

- **Data v `case_file` NEJSOU cache — koncepčně je to „spisovna“**
  (rozhodnutí 2026-07-27). Filozofie: tato data jsou
  **základní stavební kámen klíčových funkcí** (notifikace, sledování,
  historie, analýzy) — prakticky všechny analýzy se dělají nad nimi, ne nad
  infoSoudem. To, že se shromažďují oportunisticky při různých příležitostech,
  **neznamená, že jsou dočasná či postradatelná**. Důsledky: tabulka se nikdy
  jen tak nesmaže, řádky se svévolně nemažou (časem k nim přibývají užitečná
  metadata — vazby jednání, oblíbené, budoucí atributy). Slovo „cache“
  v novém kódu, dokumentaci ani UI nepoužívat; starší texty se budou
  převádět postupně. (UI už dnes říká „načtený/evidovaný spis“.)
  „Spisovna“ je **jen český koncepční pojem pro dokumentaci/UI — v kódu se
  nic tak nejmenuje** (rozhodnutí 2026-07-28): pojmenovává se podle obsahu,
  ne podle významu kontejneru; kontejner v kódu reprezentuje repository.
- **Pojmenování objektů** (rozhodnutí 2026-07-27, upřesněno 2026-07-28):
  **`CaseFile`** pro spis (holé `Case` nejde, je to rezervované slovo PHP;
  navazuje na zavedené `CaseYear`/`CaseSummaryService`/`caseChip`),
  **`CaseQuery`** výhradně pro **hledání spisů** (formulář na HP, kladení
  dotazů), **`Document`** rezervováno pro budoucí nahrávané soubory (PDF
  rozsudky ap.) — těm se nikdy neříká „file“, aby nekolidovaly se spisem.
  **Vlna `Proceeding` → `CaseFile` je hotová (2026-08-20)** a byla schválně
  odložená až za typový refactoring: doména žije v `App\Model\CaseFile`,
  tabulky jsou `case_file`/`case_file_event`/`case_file_relation` a FK sloupec
  `case_file_id` (migrace `2026-08-20-00`); zároveň se sjednotily názvy indexů
  na prefix `idx_` (`2026-08-20-01`). Zbývá **plošné přejmenování „Spisovka“**
  — samostatná vlna, čistě kódová (v DB se pojem nevyskytuje), viz
  [docs/roadmap.md](docs/roadmap.md).

## O projektu

- **Jazyk rozhraní:** celá aplikace je v **češtině** (UI texty, šablony, hlášky).
- **Jazyk kódu:** názvy proměnných, tříd, metod, komentáře v kódu i SQL vždy **anglicky**.
  Code-related sem patří i **CI/workflow soubory, testy a commit messages** — taky **anglicky**
  (včetně názvů kroků/úloh, ty drž jako stručné štítky, ne souvětí).
- **Komunikace v tomto repu (CLAUDE.md, odpovědi):** česky.

## Technologický stack

- **PHP 8.5** — preferuj moderní jazykové konstrukce (typed properties, enums, readonly,
  first-class callable, match, named args, property hooks, `#[\Override]` apod.).
- **Nette Framework** (aplikační framework).
- **MariaDB 10.5** — produkční cíl je 10.5.29; lokální devstack běží na image
  `jakubboucek/lamp-devstack-mysql:10.5`, takže dev i produkce sedí na stejné major verzi.
- **Frontend:** Vite 6 + **Tailwind CSS v4 + daisyUI v5** — jediný entry point
  (`assets/main.js` → `assets/css/app.css`), neutrální témata `light`/`dark` dle
  `prefers-color-scheme`. Aplikace je
  **záměrně utilitární** („rozhraní pro přehledné zobrazení dat“), žádná vizuálně atraktivní
  část se nechystá. Viz sekce *Frontend*.

## Lokální vývoj (Docker)

Vývoj běží výhradně přes docker-compose stack
[docker-lamp-devstack](https://github.com/jakubboucek/docker-lamp-devstack). **Nikdy nespouštěj
`php`, `composer` ani `mysql` přímo na hostu** — vždy přes kontejner služby `web`.

```bash
docker compose up -d          # nastartuje stack (web + mysqldb)
docker compose down           # zastaví stack
```

Do kontejneru `web` se mountuje i `docker/php/devstack.ini` (→ `conf.d`) — zvedá limity
uploadu kvůli importu v sekci System; na produkci se limity nastavit nedají, viz
[docs/sync.md](docs/sync.md).

Aplikace běží na **http://localhost:8080** (port 80 v kontejneru → 8080 na hostu).
Pozor: stejné porty (8080/33060/8088) používá i devstack projektu `survivor-lodin` —
oba stacky nemohou běžet současně.

### Spouštění příkazů v kontejneru

Celý kořen repa je v kontejneru namountován do `/var/www/html`; webová aplikace tedy leží v
`/var/www/html/web` a CLI tooly mimo hosting v kořeni (`/var/www/html/…`). Příkazy pro webovou
aplikaci pouštěj s working directory `-w /var/www/html/web` (tam je `composer.json` aplikace):

```bash
docker compose exec -w /var/www/html/web web php …       # PHP CLI nad aplikací
docker compose exec -w /var/www/html/web web composer …  # Composer (v image předinstalován)

# CLI tool ležící mimo web/ (např. bin/) – pouštěj z kořene /var/www/html:
docker compose exec -w /var/www/html web php bin/<tool>.php
```

### Vytěžování dokumentů (image `jakubboucek/pdftools`)

Zdrojová data justice nechodí jen přes API — **rozvrhy práce** soudů (autoritativní seznam
soudních oddělení, rejstříků, specializací a podílů nápadu) se publikují jako PDF, u některých
soudů jako `.docx`/`.xlsx`. Na jejich vytěžování je samostatný image, **ne součást běžícího
stacku**: služba `pdftools` má `profiles: [tools]`, takže ji `docker compose up` nestartuje,
a `web` se kvůli tomu nemodifikuje (změny v cizím image by stejně restart nepřežily).
Zdroje: [docker/pdftools/](docker/pdftools/), build `docker build -t jakubboucek/pdftools
docker/pdftools` (~520 MB, dev-only, na hosting se nenahrává). Image je určený i k publikaci
do veřejného registru, proto nesmí obsahovat nic projektově specifického.

Volá se přes wrapper [bin/pdf](bin/pdf) (cesty jsou relativní ke kořeni repa):

```bash
bin/pdf                                          # výpis příkazů image
bin/pdf pdf-probe .data/rozvrh.pdf               # co to je + jestli bude potřeba OCR
bin/pdf pdf-text .data/rozvrh.pdf                # text (u skenu sám spustí OCR)
bin/pdf pdf-tables .data/rozvrh.docx             # tabulky jako TSV (jen Office formáty)
bin/pdf pdf-pages --pages=1-3 --out=.data/x .data/rozvrh.pdf   # stránky do PNG
```

Co je dobré vědět:

- **Textová vrstva vs. OCR** rozhoduje `pdf-text` sám podle znaků na stránku (práh 100);
  `--ocr=force` vynutí OCR i u dokumentu s textem, `--ocr=never` ho zakáže. OCR jede
  `ces+eng` a na české diakritice je ověřené.
- **`pdf-pages` je jediná cesta, jak si PDF prohlédnout očima** — nástroj `Read` na PDF
  potřebuje `pdftoppm` na hostu, ten tam není. Vyrenderované PNG už `Read` zobrazí, což
  je u tabulek se sloučenými buňkami často jediné spolehlivé čtení.
- **Office formáty jsou lepší zdroj než PDF**, ne horší: nesou skutečné tabulky, takže
  `pdf-tables` vrací řádky bez dohadování podle mezer. Z PDF se struktura jen rekonstruuje
  (`pdftotext -layout`).
- **Nula je hodnota, ne prázdno.** `pdf-office` schválně rozlišuje `None` od `0` —
  „rozsah nápadu 0 %" je právě ten údaj, podle kterého se pozná spící oddělení.
- **LibreOffice v image záměrně není** (zdvojnásobil by velikost). Doplní se, až narazíme
  na soubor, se kterým si strukturní knihovny neporadí, nebo až bude potřeba konverze
  Office → PDF kvůli vizuálnímu čtení.

Poznatky o tom, co z rozvrhů plyne pro číselník senátů, patří do
[docs/architektura.md](docs/architektura.md) — pozor, **rozvrh uvádí i zaniklá oddělení**
(figurují tam jen jako „pravomocně skončené spisy dle předchozích rozvrhů práce“), takže
každý nově objevený senát je nutné ověřit dotazem na infoSoud.

### Rate-limiting proxy pro hromadné stahování (služba `ratelimiter`)

Justiční aplikace (infoSoud, infoJednání, infoDeska, msp.gov.cz) sdílí jeden fyzický
server — samovolně stanovený limit je **1 req/s celkem**, ne per API. Pro jednorázové
hromadné stahování mimo standardní PHP klienty (typicky paralelní agenti s curl) slouží
dev-only sidecar `ratelimiter` (nginx, `profiles: [tools]` — běžné `docker compose up`
ho nestartuje):

```bash
docker compose up -d ratelimiter    # start (explicitní jméno služby obejde profil)
docker compose stop ratelimiter     # stop po skončení akce
```

Použití: `curl http://localhost:8090/<služba>/<cesta>` — prefix `/infosoud/`,
`/infojednani/`, `/infodeska/` nebo `/msp/` se odstřihne a zbytek jde na
`https://<služba>.gov.cz/<cesta>`. Requesty nad 1 req/s nginx **zdržuje ve frontě**
(`limit_req burst=120`, jeden globální kbelík pro všechny upstreamy i klienty);
teprve přetečení fronty vrací 429. Důsledky pro klienty: **velkorysý timeout**
(request může čekat pozice-ve-frontě sekund, např. `curl --max-time 180`) a pozor
na `curl -L` — `proxy_redirect` sice `Location` přepisuje zpět na proxy, ale
redirect na jiný host by z limitu utekl. User-Agent vnucuje proxy (stejný jako
`JsonHttpClient`). Průběh: `docker compose logs -f ratelimiter`
(PASSED/DELAYED/REJECTED). Konfigurace: [docker/ratelimiter/](docker/ratelimiter/);
další upstream = jeden location blok. Ověřeno 2026-08-31: 4 paralelní requesty
odbaveny v rozestupech ~1 s, limit platí napříč službami. PHP klienti
(`JsonHttpClient`) přes proxy nejdou — centrální limiter pro aplikaci samotnou je
zatím jen návrh (DB rezervace slotů, viz diskuse 2026-08-31).

### Databáze

| Přístup            | Host      | Port    |
|--------------------|-----------|---------|
| Z PHP (kontejner)  | `mysqldb` | `3306`  |
| Z hostu (klient)   | `127.0.0.1` | `33060` |

Přihlášení: uživatel `root`, heslo `devstack`, databáze `default`.

```bash
# MySQL klient v kontejneru:
docker compose exec mysqldb mysql -uroot -pdevstack default
```

**Připojení k DB** je v `web/config/common.neon` (`database: dsn: 'mysql:host=mysqldb;dbname=default'`,
dev creds root/devstack). Na hostu je k dispozici i **Adminer na http://localhost:8088**
(server `mysqldb`).

**⚠️ Repo neobsahuje kompletní DB ani dump** a obsahovat nebude — v `/migrations/structures/` jsou
jen přírůstkové změny struktury. Funkční databázi (data) je nutné získat odjinud, naimportovat a
doaplikovat novější migrace. Počítá se s tím, že dev DB se bude plnit ostrými daty z produkce.

### Konfigurace a první spuštění

`web/config/local.neon` je **povinný** — `Bootstrap.php` ho načítá **vždy** (ne podmíněně), bez něj
se aplikace nespustí. Je gitignorovaný; vytváří se zkopírováním verzovaného vzoru
`web/config/local.sample.neon`. Slouží k per-prostředí override (typicky DB creds na produkci).
Pro lokální dev stačí defaulty z `common.neon`. **Pozor:** holý klíč `database:` se všemi potomky
zakomentovanými znamená `database: null` a shodí DI extension — odkomentovávej vždy celý blok.

Kroky po čerstvém klonu (README je záměrně netechnické, postup žije jen tady): composer install
v kontejneru, `mkdir -p web/temp web/log`, `cp local.sample.neon local.neon`, ruční aplikace
**všech** migrací z `migrations/structures/` po pořadí (+ příslušné datové z `migrations/data/`),
`npm install && npm run build` na hostu, založení uživatele přes `bin/create-user.php`.

## Adresářová struktura

**Na webhosting se nahrává pouze adresář `web/`** (jeho document root je `web/www`). Zbytek kořene
repa (CLI tooly, dev infrastruktura) na hosting nepatří, ale je dostupný v dev kontejneru.

```
lexion/                     # kořen repa = celý projekt (mountuje se do /var/www/html)
├── docker-compose.yml      # jen lokální vývoj, na hosting se nenahrává
├── .docker/                # data MariaDB (gitignored), nenahrává se
├── .data/                  # lokální pracovní data (gitignored), nenahrává se;
│                           #   .data/spa-archive/ = klon samostatného neveřejného
│                           #   repa s podklady k analýze zdrojových dat justičních
│                           #   aplikací (má vlastní CLAUDE.md s detaily)
├── bin/                    # CLI tooly MIMO hosting – spouští se lokálně v Dockeru
│   ├── create-user.php     # založení/aktualizace uživatele
│   ├── infosoud-fetch.php  # stažení řízení z infosoudu do spisovny (1 řízení, nebo --list=<soubor>;
│   │                       #   --delay, --skip-fresh=<dny>, --no-first-event — viz Stahovací tooly)
│   ├── infosoud-fetch-hearings.php  # detaily jednání (JED_*) řízení z infosoudu
│   ├── infosoud-scan-series.php # adaptivní sken číselných řad spisů (konec řady + díry;
│   │                       #   --list/--from/--to/--estimate/--confirm/--dry-run/--max-requests)
│   ├── infojednani-scan.php # sken všech síní × dnů z infoJednání do .data/
│   ├── infojednani-import.php # import skenu do tabulek hearing*
│   ├── hearing-bind.php    # párování hearing ↔ case_file (guess/confirm, --dry-run)
│   └── pdf                 # wrapper nad image pdftools (PDF/OCR/Office) – shell, ne PHP
├── assets/                 # FRONTEND zdroje – mimo hosting, build na hostu
│   ├── main.js + css/app.css     # jediný entry (Tailwind + daisyUI light/dark);
│   │                       #   main.js importuje dialog.js, copy-button.js
│   │                       #   a strip-tracking-url-params.js
│   └── spisovka/           # Vue island toolu spisovky – samostatný chunk,
│                           #   načte se dynamicky jen na stránce s formulářem
├── docker/                 # konfigurace dev kontejnerů, verzovaná
│   ├── php/devstack.ini    # → conf.d kontejneru web
│   └── pdftools/           # image jakubboucek/pdftools (PDF/OCR/Office), viz Vytěžování dokumentů
├── docs/                   # dokumentace projektu (zadání, architektura, analýzy API) + data/ a img/
├── migrations/
│   ├── structures/         # SQL migrace struktury (aplikují se ručně)
│   └── data/               # datové migrace = PHP CLI skripty (viz Databázové migrace)
├── node_modules/           # npm závislosti (gitignored) – mimo hosting
├── package.json            # FE závislosti a scripty (npm run dev/build/watch) – mimo hosting
├── vite.config.ts          # konfigurace Vite – mimo hosting
└── web/                    # << TENTO adresář se nahrává na webhosting
    ├── www/                # DOCUMENT ROOT (jediná veřejně přístupná část)
    │   └── assets/         # Vite BUILD OUTPUT – VERZOVANÝ v gitu (commituje se, viz Frontend)
    ├── app/                # Nette aplikace (presentery, model, šablony) – mimo document root
    │   ├── Core/           # infrastruktura (Authenticator, RouterFactory)
    │   ├── Model/          # doménové služby a repository
    │   │   ├── Codelist/   # číselníky: CourtRepository, RegistryRepository (3 formy rejstříku), CourtLevel, CourtRegion (soudní kraj 1960, `court.region` = prostřední 3 znaky infosoud kodu, NULL pro NS/NSS), …
    │   │   ├── Spisovka/   # Spisovka (value object), SpisovkaParser (human vstup), SpisovkaSlugParser (URL), SpisovkaFactory, SpisovkaResolver
    │   │   ├── Infosoud/   # InfosoudClient (API), InfosoudLinkBuilder (deep-linky), enums InfosoudEventType/InfosoudEventAttribute/InfosoudCollegium, InfosoudHearing (parsování JED_* atributů)
    │   │   ├── Favorite/   # FavoriteRepository, FavoriteGroupRepository (oblíbené spisy uživatele)
    │   │   ├── Hearing/    # HearingRepository (evidence jednání z infoJednání)
    │   │   ├── CaseFile/   # CaseFileRepository — spisovna; CaseSummaryExtraction (payload → derivované sloupce); CaseSummaryService (jen NS atributy)
    │   │   └── Sync/       # jednosměrný aditivní sync mezi prostředími (export/import JSONL) – docs/sync.md
    │   └── Presentation/   # UI vrstva (viz Členění aplikace)
    ├── tests/              # nette/tester (composer tester); bootstrap + Model/*.phpt
    ├── config/             # NEON konfigurace
    ├── phpstan.neon        # PHPStan level 8 (viz Konvence)
    ├── latte-lint          # linter šablon (spouští se v kontejneru)
    ├── vendor/             # Composer závislosti (gitignored) – mimo document root
    ├── temp/               # cache (gitignored)
    └── log/                # logy (gitignored)
```

**Dvě roviny „co je kde dostupné“:**
- **Hosting:** nahrává se jen `web/`, web servíruje pouze `web/www`; `app/`, `config/`, `vendor/`
  leží mimo document root, takže nejsou stažitelné z webu.
- **Dev kontejner:** mountuje se celý kořen, proto jsou v Dockeru dostupné i CLI tooly mimo `web/`
  (kvůli jiné verzi PHP na hostu je chceme spouštět v kontejneru).

Mapování v `docker-compose.yml`: kořen repa (`.`) → `/var/www/html`,
`APACHE_DOCUMENT_ROOT` = `/var/www/html/web/www` (odpovídá `web/www`).

## Stahovací tooly (`bin/`)

**Stahovaný celek není jedna věc, ale seznam artefaktů** (rozhodnutí 2026-08-26,
zavedeno v `bin/infosoud-fetch.php`): u spisu jde dnes o **přehled řízení**
(`case_file.infosoud_at`) a **detail první vlastní události**
(`case_file_event.detail_fetched_at`); výhledově přibudou další pravidla, čím
se má timeline doplnit (např. budoucí nařízená jednání analogicky
k `bin/infosoud-fetch-hearings.php`).

Z toho plynou závazná pravidla pro každý stahovací tool:

- **Čerstvost se posuzuje per artefakt, ne per spis.** `--skip-fresh=<dny>` je
  společný práh, ale uplatní se na každý artefakt zvlášť podle **jeho vlastního**
  časového razítka. Čerstvý přehled tedy nesmí zabránit dotažení detailu, který
  chybí — jinak by kombinace „stáhni s `--no-first-event`, pak dožeň zbytek“
  nikdy nedoběhla.
- **Co nebylo staženo, není čerstvé** — bez ohledu na to, jak čerstvý je řádek,
  který na artefakt čeká. Prázdné razítko není „nedávno ověřeno“.
- **Přepínač `--no-first-event` říká „tenhle artefakt nechci“**, ne „je čerstvý“;
  je to volba rozsahu, ne prahu, a obojí se vyhodnocuje nezávisle.
- **Detail události stahuje vždy `EventDetailService`** (jediné místo s integritní
  pojistkou proti přečíslování). Po jeho stažení je nutná **reprojekce**
  (`CaseFileProjectionService::projectInfosoud()`) — subject si řádek dorovná sám,
  ale vazba `PRED_VEC` se odvozuje z toho detailu projekcí. Pozn.: lazy fetch na
  webu reprojekci **nedělá**, vazba tam vznikne až při dalším refreshi spisu.
- **Deterministické neúspěchy se evidují** (2026-08-28): tabulka
  `case_lookup_miss` — not found / odmítnutí / nesoulad ročníku, zapisuje
  `CaseFileSyncService` u všech fetchů včetně webových; trvalost missu se
  **počítá při čtení** (`isPermanent()`), transientní chyby jdou jen do
  aplikačního logu. Fetcher má `--skip-exists` (přeskočí kdykoli stažený
  artefakt i trvalé missy — režim pro skeny starých ročníků; pozor,
  **přepínače musí být před pozičními argumenty**, getopt() dál neparsuje).
  Viz [docs/architektura.md](docs/architektura.md).
- **Adaptivní sken číselných řad** (2026-08-28): `bin/infosoud-scan-series.php`
  nad `CaseSeriesScanService` — vyplní díry a najde konec řady logaritmickým
  počtem sond (galloping + bisekce, čistý automat `CaseSeriesEndSearch`), konec
  zapíše do `case_series_scan` (jen když ho potvrdí; blok = pětice + `number_from`,
  víc pásem/senát). Odmítá nesenátní rejstříky (INS, EPR, ICM, EXE, NT, NC).
  Rozhodnutí každé sondy jdou do JSONL běhu (log kind `series-scan`). Viz
  [docs/navrh-sken-rad.md](docs/navrh-sken-rad.md).

## Frontend (Vite / npm)

Frontendový tooling **záměrně leží v kořeni repa, ne ve `web/`** — aby se `node_modules` ani
zdroje nenahrávaly na hosting. Na webhosting jde jen zbuilděný výstup ve `web/www/assets/`.

- **Zdroje:** `assets/`, **jediný entry point `main.js`** (`css/app.css` = Tailwind + daisyUI,
  neutrální témata `light` + `dark --prefersdark`; `<html>` nemá `data-theme`, přepíná se čistě
  podle `prefers-color-scheme`, takže Tailwindí `dark:` varianta — media query — je s daisyUI tématem
  synchronní). Žádné oddělené public/admin bundly — celá aplikace sdílí jeden
  utilitární vzhled.
- **Build výstup:** `web/www/assets/` — **záměrně VERZOVANÝ v gitu**. Po změně čehokoli
  v `assets/` **spusť `npm run build` a výstup commitni** (jinak se rozejde se zdroji).
  `emptyOutDir: true` čistí adresář při každém buildu.
- **Node běží na HOSTU, ne v kontejneru** — devstack image je LAMP bez Node:

  ```bash
  npm install
  npm run dev      # Vite dev server + HMR
  npm run build    # produkční build do web/www/assets/
  npm run watch    # vite build --watch (build při každé změně zdrojů)
  ```

- **Vue** (od 2026-08-16) je jen pro **islands** — interaktivní části stránky, zbytek
  webu zůstává serverem renderované HTML. Island se načítá `import()`em podle
  přítomnosti mount pointu, takže Vue (~40 kB gzip) platí jen stránky, které ho
  potřebují; ostatní stránky tím naopak odlehčily (Tom Select odešel z hlavního
  bundlu do islandu: `main.js` 23,7 → 5,2 kB gzip). SFC překládá `@vitejs/plugin-vue`.
- **Tom Select + nette-forms:** select soudu je vyhledávací combobox přes
  **Tom Select** (npm závislost; `app.css` importuje jeho CSS a přebíjí ho na
  daisyUI vzhled — je to největší kus vlastního CSS v projektu). Ve Vue islandu ho
  obaluje `assets/spisovka/tomSelect.js` — Vue mu jen říká, které soudy jsou
  v nabídce a který je navržený; záměrně se nenahrazuje Vue comboboxem (fulltext
  nad 98 soudy, optgroups, klávesnice a ~90 ř. CSS by se psalo znovu). Klientskou
  validaci **ostatních** formulářů dodává npm balíček **`nette-forms`**
  (`netteForms.initOnLoad()` v `main.js`).

- **Napojení na PHP:** Nette Assets (`assets:` v `common.neon`) čte manifest
  z `web/www/assets/.vite/`; v šablonách `{asset 'main.js'}` (v layoutech `{asset? 'main.js'}`).
- **Tailwind scan:** Tailwind nečte Latte, jen skenuje text — `app.css` má
  `@source "../../web/app/Presentation/**/*.latte"`. Skládané názvy tříd (`text-{$x}`)
  se nedetekují — používej celé názvy.
- **Ikony:** Iconify plugin (`@plugin "@iconify/tailwind4"` v `app.css`), sada **Material
  Symbols Light** (`@iconify-json/material-symbols-light`, dev závislost). V šabloně
  `<span class="icon-[material-symbols-light--<název>]" aria-hidden="true"></span>` — buildí se
  jako CSS mask s `currentColor` (dědí barvu textu, dark mode zadarmo), do bundle jdou jen
  použité ikony. V textu ikonu usaď přes `align-[-0.125em]`. Šipka `→` v nadpisech zůstává
  záměrně unicode (propisuje se do `<title>`). **Stavové bookmark ikonky spisu** (načtený/
  udržovaný/sledovaný) = define v `Presentation/@bookmark.latte`, mapování stavů viz
  [docs/architektura.md](docs/architektura.md) — presentery zatím generují jen `none`/`loaded`
  (`maintained`/`watched` čekají na monitoring); v uživatelských textech nepoužívat slovo
  „cache“ (technicky přesné, uživatelsky matoucí) — říkáme „načtený/evidovaný spis“.
  **Spisové značky** se všude vypisují přes define `Presentation/@spisovka.latte` —
  primary čip s rámečkem a podbarvením, font dědí z okolí (žádné mono), em-based
  padding škáluje od H1 po drobný text; odkazová varianta = čip obalený
  `<a class="link-hover">`.
- **Kdy zakládat Latte define** (rozhodnutí 2026-08-16): define je na místě jen tam, kde
  fragment nese **netriviální logiku nebo pravidlo** — `case-chip` rozhoduje, kdy je spisovka
  odkaz, `bookmark` mapuje stav spisu na ikonu a text. **Opakující se čisté HTML se
  nededuplikuje**: `<button n:name="send" class="btn btn-primary mt-2">`, obal formuláře nebo
  jednořádkový výpis chyb pole (`<p n:foreach="$form['x']->getErrors() …">`) mají zůstat
  v šabloně tak, jak jsou. Důvod: přímo čitelné HTML vidí i IDE a statická analýza, kdežto
  `{include}` je vrstva navíc s nulovým přínosem, a argument „až se to bude měnit“ neobstojí —
  výskyty najde fulltext. Mírná duplicita je levnější než abstrakce s přepínači.
- **Odsazení:** 4 mezery (PHP/JS/Latte), 2 mezery NEON/YAML — viz `.editorconfig`.

## Databázové migrace

Jakákoli změna struktury DB (DDL) se zakládá jako **SQL soubor v `/migrations/structures/`**
(adresář v kořeni repa, mimo `web/`).

- **Pojmenování:** `YYYY-MM-DD-XX-popis.sql`
  - `YYYY-MM-DD` — datum vzniku migrace,
  - `XX` — pořadové číslo v rámci dne, od `00`,
  - `popis` — krátký popis (anglicky, kebab-case).
  - Příklad: `2026-07-17-00-create-user-table.sql`.
- **Kolace:** všechny tabulky a sloupce **vždy `utf8mb4_unicode_520_ci`** (charset `utf8mb4`).
  V každém `CREATE TABLE` proto `DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_520_ci`.
- **Jedna změna = jeden soubor** (rozhodnutí 2026-08-26). Když kroky na sebe navazují
  a dávají smysl jen jako celek (přidat sloupce → naplnit je → uklidit, co nahrazují),
  patří **do jednoho souboru v pevném pořadí**, ne do pěti. Kritérium není „DDL vs. DML“,
  ale „je to jedna věc?“. Do `/migrations/data/` jde jen **striktně data-only** migrace —
  transformace či oprava dat bez souvisejícího zásahu do struktury.
- **Rozpracovanou migraci klidně přepiš.** Pravidlo „migrace se nemění“ platí až pro
  nasazenou; dokud pracuješ na jednom celku (a soubor nikde neběžel než na tvém devu),
  je správné soubor upravit, ne přidávat opravný. Když už na devu běžel, ověř přepsanou
  verzi na čisté kopii DB ze zálohy (`CREATE DATABASE migration_test` + `mysqldump`
  ze `.backups/`), ne dalším souborem.
- **Transformace dat:** datové migrace žijí v `/migrations/data/` (pojmenování
  `YYYY-MM-DD-XX-popis.php|sql`) a mají **dvě podoby**:
  - **PHP CLI skript** — když transformace potřebuje aplikační logiku (parsery, služby).
    Bootstrapuje Nette DI přes `(new Bootstrap)->bootConsoleApplication()`, spouští se
    v kontejneru (`docker compose exec -w /var/www/html web php migrations/data/<skript>.php`,
    podpora `--dry-run`).
  - **SQL soubor** — když transformaci **půjde vyjádřit v SQL**. Preferuj ho vždy, když má
    oprava doběhnout i na produkci: **deploy nahrává jen `web/`**, takže `migrations/` na
    produkčním hostu vůbec není a PHP skript tam nespustíš. SQL se dá pustit z Admineru.
    Piš je idempotentní a co nejužší (`WHERE` na dotčený typ řádků), v hlavičce komentářem
    ověřovací `SELECT` a poznámku o pořadí vůči deployi kódu.
- **Před datovou migrací vždy udělej zálohu DB** (`mysqldump` do gitignorovaného `/.backups/`).
- **Spouštění:** migrace se **NEspouštějí automaticky** — vše aplikuje obsluha ručně.

## Členění aplikace a routování

Aplikace má **tři zóny se společným utilitárním vzhledem** (daisyUI light/dark) a **jediným
sdíleným layoutem `Presentation/@layout.latte`** (Panel vlastní layout nemá — Nette ho
najde konvencí o úroveň výš; navbar Panel nijak nerozlišuje — logo vede vždy na HP,
jediné výjimky: HP je „čistá“ (bez navigace mezi stránkami, jen „Můj přehled“ + případný
user dropdown), Sign nemá menu vůbec, jinak se rozlišuje jen přihlášen/nepřihlášen;
patička s odkazem na `/o-projektu` je společná):

**Brand assety (logo):** SVG varianty ve `web/www/img/logo/` (logo = ikona+text, wordmark,
ikona, `-d2` zjednodušená pro favicon; každá i v `-heavy` verzi se ztluštěnými tahy pro malé
velikosti — tenké 1.3 tahy a hairlines fontu se při zmenšení ztrácejí a `shape-rendering`/
`image-rendering` na to empiricky nemají vliv). Do šablon se vkládají přes `{define}` bloky
v `Presentation/@brand.latte` jako `<img>` (`{include logo-heavy from '@brand.latte'}`,
volitelný parametr `class`); externí SVG nedědí `currentColor`, kreslí se černě — v patičce
proto wordmark dostává `class: 'opacity-60'` a v dark módu se obrací přes `dark:invert`. Favicon `web/www/favicon.svg` je ručně upravená
`-d2` (stroke 6 = 1 px při 16 px, tečkovaný oblouk, dark-mode barva přes `prefers-color-scheme`);
`/favicon.ico` má 301 redirect na SVG v `.htaccess`.

| Zóna | Popis |
|------|-------|
| **Veřejná část** | úvod, později veřejné nástroje (spisovka → odkaz, hledání soudů) |
| **Panel** (za loginem) | modul `Panel` — sledovaná řízení, uživatelský obsah |
| **System** (za loginem) | modul `System` — provozní nástroje nad celou DB (sync dat) |

- **Presentery** (mapping `App\Presentation\*\**Presenter`): `Home` (úvodní stránka
  „Google style“ = tool spisovky: velké logo + jeden formulář, nic dalšího; obsluhuje
  submity — „Otevřít“ ověří existenci a jde na detail spisu, „InfoSoud“ přeloží URL,
  třetí tlačítko „Najít příslušný soud“ je zatím disabled placeholder; jen primární
  tlačítko je bold, sekundární mají `font-normal`; pokus o spis NSS končí formulářovou
  chybou „zatím neevidujeme“; GET parametry `znacka` + `soud` (kod soudu) formulář
  předvyplní — posílá je odkaz „Zpět na vyhledávání“ z detailu spisu a JS komponenty
  je před submitem zrcadlí do URL přes `history.replaceState`, takže Zpět v prohlížeči
  vrátí vyplněné hledání i přes POST/redirect flow), `About` (veřejná statická stránka „O projektu“ na
  `/o-projektu` — povaha projektu, přístupová politika, kontakty; odkaz v patičce
  layoutu), `Spisovka` (už jen stateless JSON endpoint `validate` pro živou validaci;
  samotné `/spisovka` vrací 404 — projekt ještě nebyl veřejný, není co držet),
  `Stats` (veřejné statistiky načtených spisů na `/stats` — celkem, per soud/rejstřík/ročník,
  pokrytí zdrojů), `Spis` (veřejný detail spisu `/spis/<soud>/<znacka>` + **detail události**
  `/spis/<soud>/<znacka>/udalost/<id>` — `id` je náš PK v `case_file_event`, ne upstream
  `poradi`; timeline a související řízení se čtou z projekčních tabulek `case_file_event`/
  `case_file_relation` (plní je `CaseFileProjectionService` při každém syncu, vazby
  obousměrně přes reverzní labely číselníku `relation_type`), detail události se dočítá
  lazy (thin/full řádky, cooldown 5 min) a nesoulad typu/data s API spouští integritní
  flow — flash + redirect na spis s výzvou k aktualizaci (pozor: aktualizace zatím
  projekci jen upsertuje, zahození a přegenerování paměti událostí není zapojené —
  viz TODO v [docs/analyza-udalosti.md](docs/analyza-udalosti.md)); u NAR_JED se
  z detailu parsuje jednání (`InfosoudHearing` — čas/síň/druh z `JED_*` atributů,
  dočasné řešení než bude samostatný scraping jednání), timeline ho zobrazuje pod
  názvem události; **vícetermínová jednání** (vnořené `jednani[]` v raw JSON —
  agregace více záznamů pod jednou událostí NAR_JED/ZRUS_JED) projekce od
  2026-08-23 materializuje jako vlastní řádky `case_file_event` s odkazem na
  agregát přirozeným klíčem `parent_event_order`; navenek je vazba skrytá —
  všechny termíny bloku vypadají stejně (badge „vícedenní“ s tooltipem
  o nespolehlivosti detekce zrušení jednotlivých termínů), stránka události
  vypisuje jednotný seznam termínů bloku se zvýrazněným aktuálním — viz
  [docs/infosoud-api.md](docs/infosoud-api.md); nenačtené nabízí tlačítko „Stáhnout podrobnosti“ (signál
  `fetchEvent!` zůstává na přehledu; na stránce události je obdobný `refreshEvent!`
  pro ruční refresh detailu s vlastním 5min cooldownem), budoucí nezrušená jednání
  jsou tučně na žlutém podkladu a **události bez data** jdou mimo timeline do
  vlastního boxu; hlavičku spisu pro detail i událost sdílí šablona
  `Spis/@case-header.latte` (u NS navíc zobrazuje atributy SENAT/SLOZENI_SENATU/
  ODVOL_SOUD/PR_VEC_NS, kolegium dle rejstříku přes `InfosoudCollegium` a napadenou
  spisovku jako čip); **každý odkaz na cizí spisovku** jde přes jedno pravidlo
  (`caseChip()`/`resolveCaseReferences()` v presenteru): známý soud → odkaz na
  detail, neznámý soud + soudní rejstřík → předvyplněné hledání na HP, nesoudní
  rejstřík (spis státního zástupce) → prostý text; routa před catch-all;
  `soud` = **slug soudu** ze sloupce `court.slug` (např. `os-pm`, `ks-hk`, `ns` — městský kód
  jsou **poslední 2 znaky infosoud `kod`u** (OSSEMOP → `os-op`), prefix
  `os-`/`ks-`/`ms-`/`vs-`/`ns`/`nss` odlišuje typ soudu; výjimky: Praha má `ph` místo
  infosoudího `AB`, obvodní soudy `os-ph-01`…`os-ph-10` s nulou), `znacka` =
  slug spisovky **lowercase** `senát-rejstřík-číslo-rok` (`24-nc-3601-2024`, rejstřík jako
  jeden segment: `24-panc-141-2024`); URL se **kanonizuje 301 redirectem**
  (starý infosoud kód i špatný case → kanonický slug); cache-first přes
  `CaseFileSyncService`, ruční refresh signálem s 5min cooldownem, stale banner po
  **1 měsíci** (`StaleThreshold` — kratší práh byl otravný, spisy se reálně mění
  spíš v řádu měsíců); `/spis/` je v robots.txt disallow), `Sign` (login/logout, mimo modul Panel —
  je to brána, ne chráněná stránka), `Error\Error4xx`/`Error5xx`;
  `Panel\Dashboard` (přehled oblíbených spisů, viz *Oblíbené spisy*) — vše v modulu Panel
  extends `Panel\BasePresenter` = login-wall (`startup()` + redirect na `:Sign:in` s backlink);
  `System\Dashboard` (rozcestník sekce), `System\Sync` (export/import dat mezi prostředími —
  akce `export`/`download`/`import`, viz [docs/sync.md](docs/sync.md)) a `System\Integrity`
  (kontroly konzistence dat nad `App\Model\Integrity` + bezpečné opravné akce
  `repair!` s dry-run — dopárování síní a vazeb, obojí běh v logu; signál `record!` zapisuje
  stav kontrol do aplikačního logu — viz [docs/navrh-integrita-dat.md](docs/navrh-integrita-dat.md)) v modulu System nad
  `System\BasePresenter` = **stejný login-wall, schválně zopakovaný**, ne vytažený do společného
  předka — každá sekce si drží vlastní bránu.
- **Tool spisovky = Vue island** (od 2026-08-16, `assets/spisovka/`): server na HP
  **nerenderuje formulář**, jen mount point `#spisovka-app` se třemi oddělenými
  datovými sadami — `data-config` (URL endpointů), `data-state` (prefill z GET
  parametrů) a `data-courts` (číselník soudů po skupinách). Vlastní tool je Vue
  (`SpisovkaForm.vue`, panel `SpisovkaPanel.vue`, stav `validation.js`, obal
  Tom Selectu `tomSelect.js`), načítaný **dynamickým importem** jako samostatný
  chunk jen tam, kde mount point existuje. Bez JS formulář není a záměrně
  nemá fallback (druhá, serverem renderovaná verze by se rozešla s živou);
  `<noscript>` to řekne. `Accessory\SpisovkaInputFactory` (serverová komponenta
  pole spisovky) tím ztratila konzumenta a **byla smazána** — budoucí watch
  formulář bude taky island.
  Server odbavuje **dva JSON endpointy**: `Spisovka:validate` (živá validace,
  stateless GET) a `Spisovka:resolve` (`#[Requires(methods: 'POST',
  sameOrigin: true)]`) — ten drží pravidla submitu: fallback určení soudu přes
  `CourtCandidateService`, odmítnutí NSS a „odkážeme jen na spis, o kterém víme,
  že existuje“ (`ensureLoaded`, což zároveň naplní spisovnu); vrací buď
  `redirect`, nebo chyby klíčované polem (`znacka`/`soud`/`form`).
  **Stavová pravidla islandu:** odpověď se aplikuje jen tehdy, když popisuje text,
  který je v poli **teď** (žádné čítače sekvencí — tím padá i doručení mimo
  pořadí); requesty se při psaní neruší (PHP dotaz stejně doběhne), abort je jen
  při vyprázdnění pole; při psaní zůstává předchozí zpráva zobrazená ztlumeně,
  aby panel neproblikával, a stav nese ikona (šedý kroužek → spinner → zelená /
  modrá šipka „vyberte soud“ / žlutá / červená).
  Validace jede v režimu „reward early, punish late“: u nedotčeného pole se při
  psaní ukazují jen pozitivní zprávy (Rozpoznáno, určení soudu), chyby až po
  opuštění pole / submitu; po první zobrazené chybě se přepne do plně živého
  režimu. Validace navíc hledá spis ve spisovně `case_file`
  (`CaseFileRepository::findBySpisovka`, index `idx_case_file_spisovka`):
  jediná shoda soud **předvybere** (nikdy nepřepíše ruční volbu uživatele
  a nabídku soudů neomezuje — cache není autoritativní), víc shod jen vypíše
  seznam soudů; stejný fallback běží i na serveru v `Spisovka:resolve` při
  submitu bez vybraného soudu. **Hlášky nesmí tvrdit víc, než se stalo:** panel
  ví, který soud je v poli a jestli si ho vybral uživatel, takže rozlišuje
  „soud předvybrán“ / „spis evidujeme u soudu X“ / nabídku přepnutí (klik na
  název soudu). **Druhý zdroj kandidátů = jednání** (`HearingRepository::countPerVenueBySpisovka`,
  index `idx_hearing_spisovka`) — uplatní se, jen když cache mlčí, protože jde
  o **soud síně**, ne nutně domovský soud spisu; texty proto říkají „evidujeme
  jednání s touto značkou“, nikdy „spis je veden u…“. Pořadí: rozpoznání ze
  značky → spisovna `case_file` → jednání. Tlačítko „Otevřít“ nechá `resolve` ověřit
  existenci řízení (cache → jinak fetch z infosoudu, který rovnou naplní cache —
  detail se pak odbaví bez dalších requestů); neúspěch zůstává na stránce jako
  form-level chyba. „InfoSoud“ zůstává tupý překladač URL bez ověřování.
- **Routování** (`App\Core\RouterFactory`): `panel[/<presenter>[/<action>[/<id>]]]` → modul
  Panel (default `Dashboard:default`), `system[/<presenter>[/<action>[/<id>]]]` → modul System
  (default `Dashboard:default`), pak specifické routy `spis/<soud>/<znacka>/udalost/<id>`,
  `spis/<soud>/<znacka>` a `o-projektu`, nakonec public catch-all
  `[<presenter>[/<action>[/<id>]]]` → `Home:default`. Specifické routy (i budoucí veřejná
  API ap.) patří **před** catch-all. Žádné subdomény se nepoužívají.
- **Doménové moduly** v `app/Model/<Domain>/` — viz
  [docs/architektura.md](docs/architektura.md): `Infosoud` a `Hearing` (jednání) už
  existují, `Isir` a `Nss` zatím ne (ISIR data v `case_file.isir_json` pocházejí
  z jednorázového importu výpisů; importní tool byl odstraněn).

### Přihlášení (login-wall)

Mechanismus stojí na `nette/security`, vzor převzat ze survivor-lodin:

- **`Panel\BasePresenter::startup()`** kontroluje `$user->isLoggedIn()`; nepřihlášeného přesměruje
  na `:Sign:in` s `backlink` (přes `storeRequest()`/`restoreRequest()`). Každý chráněný presenter
  **musí** dědit z `Panel\BasePresenter`.
- **`App\Core\Authenticator`** ověřuje **e-mail + heslo** proti tabulce `user` (bcrypt přes
  `Nette\Security\Passwords`, transparentní rehash; neaktivní účet `is_active = 0` se nepřihlásí).
  Do identity ukládá `nick` a `email`.
- **Tabulka `user`** (`App\Model\UserRepository`, thin Selection API). Repository hesla nehashuje —
  hashování dělá volající. Session drží přihlášení **14 dní** (`session: expiration` v common.neon).
- **Registrace neexistuje** — účty zakládá obsluha CLI toolem:

  ```bash
  docker compose exec -w /var/www/html web php bin/create-user.php <email> <nick> <heslo>
  ```

  Když e-mail existuje, jen aktualizuje heslo/nick a účet (re)aktivuje.
- **Testovací účet pro Claude (jen lokální dev):** e-mail `claude@test.local`, heslo `claude-dev-pw`
  (nick „Claude“). **Nikdy ho nezakládej na produkci.** Když v lokální DB chybí, vytvoř ho znovu
  toolem výše.

### CSRF ochrana

Řeší ji **framework sám** přes `Sec-Fetch-Site` hlavičky (viz
[CSRF konečně řeší prohlížeč](https://blog.nette.org/cs/csrf-konecne-resi-prohlizec)):
nette/application vynucuje same-origin na **všech signálech** (`handle*`) automaticky
a nette/forms odmítá non-same-origin submit; pro prohlížeče bez Sec-Fetch
(Safari < 16.4) je fallback `_nss` cookie. **Pozor na verze:** Sec-Fetch mechanismus
mají až nette/application ≥ 3.3, nette/forms ≥ 3.3 a nette/http ≥ 3.4
(`Request::isFrom()`); composer.json má proto tato minima vynucená — nesnižovat. **Nepřidávat ruční tokeny.** Vedlejší
efekt: non-browser klienti (curl, crawlery, prefetchery) bez `_nss` cookie signál
nespustí — GET odkazy na signály tedy nespouštějí fetch z justice cizím robotům.
Budoucí záměrně cross-origin endpoint se povoluje přes `#[Requires(sameOrigin: false)]`.
Empiricky ověřeno 2026-07-27: cross-site i holý curl na `?do=refresh` skončí
redirectem **bez provedení signálu**; same-origin formuláře a signály fungují.

### Oblíbené spisy

Per-user záložky nad cache řízení (migrace `2026-07-20-00-create-favorite-tables.sql`):

- **Datový model:** `favorite` (user × case_file, unikátní pár; volitelný vlastní `name`,
  `group_id` NULL = obecný seznam, `position` = ruční pořadí v rámci bucketu) a
  `favorite_group` (per-user skupiny, ruční pořadí). FK na `case_file` **záměrně bez
  CASCADE** — oblíbené jsou uživatelská data a nesmí tiše zmizet se smazáním cache řádku;
  FK na skupinu má `ON DELETE SET NULL` jen jako pojistku, aplikace před smazáním skupiny
  spisy přesouvá do obecného seznamu (`FavoriteRepository::ungroupAll`). Pozice se po každé
  mutaci přečíslovávají 1..n per bucket; řazení = swap se sousedem (šipky, žádný drag&drop).
- **Detail spisu (`Spis`):** přihlášený uživatel má v boxíku hvězdičku — outline otevírá
  modal s nepovinným názvem (komponenta `favoriteForm`), žlutá plná otevírá potvrzení
  odebrání (signál `removeFavorite!`). Vlastní název se zobrazuje **za** spisovkou v H1
  a **před** spisovkou v `<title>` (na stránce události zůstává title beze změny).
  Modaly = nativní `<dialog>` + daisyUI `.modal`, otevírané delegátem
  `assets/dialog.js` přes `[data-dialog-open="<id>"]`.
- **Panel Dashboard:** přehled po sekcích (obecný seznam, pak skupiny v ručním pořadí)
  se sloupci vlastní název + spisovka, předmět, stav řízení (obojí ze sloupců `case_file`);
  akce editFavorite/editGroup (formuláře), signály move*/remove* s kontrolou vlastnictví
  (`user_id`, cizí id → 404), zakládání skupin inline formulářem (duplicitní název chytá
  `UniqueConstraintViolationException` z unikátního klíče).
- **Plán dalších iterací** (sdílené atributy spisu, osoby v řízení, seznam posledních
  hledání, budoucí sledování `watch` vycházející z oblíbených): viz
  [docs/roadmap.md](docs/roadmap.md), sekce *Menší záměry* a *Monitoring*.
  Pozn.: localStorage pamatování posledního soudu bylo revertováno (nefunguje dobře při
  práci ve více tabech) — nahrazeno prefillem formuláře z URL parametrů.

## Deployment (FTP)

Nasazení na produkci (lex.ion.cz) řeší **dg/ftp-deployment** (nainstalovaný globálně přes
Composer **na hostu**, ne v kontejneru). Konfigurace: [.deployment.php](.deployment.php)
(nahrává jen `web/`, ignoruje dev soubory — `phpstan.neon`, `latte-lint`, `composer.json/lock`,
`config/local.neon`, `data/`, `log/`, `temp/`, `tests/`, `www/upload/`; `allowDelete: true`
+ purge `temp/cache`).
Credentials jsou v gitignorovaném `.deployment-credentials.php` (struktura je popsaná
v komentáři v `.deployment.php`) — **nikdy je necommituj ani nevypisuj**.

```bash
bin/pre-deploy.sh   # composer install --no-dev v kontejneru (produkční vendor/)
bin/deploy-dry.sh   # zkouška bez nahrávání (-t)
bin/deploy.sh       # ostrý deploy
# po deployi vrať dev závislosti:
docker compose exec -w /var/www/html/web web composer install
```

Pozn.: `/web/data/` je v deploy `ignore` záměrně — jinak by `allowDelete` smazal serverová
data. Stavový soubor deploymentu (`web/.htdeployment`) je gitignorovaný.

## Testování webu

Webovou část testuj proti `http://localhost:8080` přes **chrome-devtools-mcp nebo vestavěný
browser pane** (obojí je povolené) — ne přes čisté curl, ať se ověří i klientské chování a Tracy
výstup (viz skill `nette:tracy-debugging`, Tracy mirroruje výstup do konzole, čti přes
`list_console_messages` / `read_console_messages`). Rozšíření „Claude in Chrome“ jen na výslovné
vyžádání.

**Debugging:** při chybě čti **horní výjimku** v Tracy BlueScreen (přes konzoli), ne grepem na
tipované řetězce. Pozor: v debug módu se **`BadRequestException` (404) navenek vrací jako HTTP
500** (BlueScreen); v produkci je to korektní 404 přes `Error4xx`. Chybové stránky se proto
testují **v produkčním módu**: debug určuje `Redbitcz\DebugMode\Detector` (dev ho zapíná
`APP_DEBUG: 1` v docker-compose), vypne ho cookie `app-debug-mode=0` — a po přepnutí je nutné
**smazat `web/temp/cache`**, jinak se použije zastaralý produkční DI kontejner z minula.

**Statická analýza:** `docker compose exec -w /var/www/html/web web composer phpstan` (level 8
nad `app/`, `../bin` i `../migrations/data`; ignorují se už jen untyped arrays/generika thin
repositories a `$argv` v CLI — **ignore na magické property `ActiveRow` byl 2026-08-05 zrušen**,
model vrací jen entity, viz `web/phpstan.neon`). Šablony:
`composer latte-lint`. **Testy:** `composer tester` (nette/tester, `web/tests/`; převážně
čistá logika bez DB; testy bootující DI a čtoucí DB — `RegistryCodelistConsistency`,
`SpisovkaSlugParser` — se bez dostupné DB samy skipnou). **Vše najednou:**
`docker compose exec -w /var/www/html/web web composer check` (phpstan + latte-lint + tester).

## Konvence pro Claude

- Dodržuj odlišení jazyků: **UI česky, kód anglicky** (viz výše).
- **Registrace služeb** (rozhodnutí 2026-08-23): DI `search:` v `services.neon`
  auto-registruje jen suffixy, které v projektu **vždy** znamenají službu
  (`*Facade`, `*Factory`, `*Repository`, `*Service`, `*Client`, `*Provider`).
  Do seznamu **nepřidávej další suffixy** — třídy jako `*Parser`/`*Resolver`/
  `*Builder` můžou být stejně dobře produkty factory (viz `LogRunBuilder`,
  který kdysi auto-registrace omylem chytla) a registrují se **explicitně**
  v sekci `services:`.
- **Latte šablony deklarují vstupy:** na začátku šablony výčet proměnných předávaných
  z presenteru značkou `{varType}`. Latte má omezenou syntaxi typů — generika typu
  `array<int, string>` nezná; používej `Type[]`, `?Type`, `array`; třídy z globálního
  namespace (`stdClass`) potřebují úvodní `\`, jinak je PhpStorm nebere jako typ.
  **Výchozí proměnné Nette se nedeklarují** (`$basePath`, `$baseUrl`, `$user`,
  `$presenter`, `$control`, `$flashes` — PhpStorm je zná jako předdefinované);
  `$form` deklaruje šablona s formulářem.
- **Typové entity (hotovo 2026-08-05):** doménové modely používají
  **`jakubboucek/hydrator`** (registrovaná `HydratorFactory`, formát
  `NetteDatabase`, app TZ Europe/Prague). Entita = typované public properties
  + marker interface `Entity`, bez atributů a konstruktoru; repository vrací
  entity a bere je i na zápisu (částečně vyplněná entita = patch). **Převedené
  jsou všechny domény** — `Model/User/`, `Model/Favorite/`, `Model/Hearing/`,
  `Model/CaseFile/` (entity `CaseFile`, `CaseFileEvent`, `CaseFileRelation`)
  i `Model/Codelist/`. `ActiveRow` ani `Selection` z modelu nevychází a PHPStan
  to hlídá (plošný ignore zrušen). Enum se zavádí jen tam, kde množinu hodnot
  drží i DB (CHECK). Kompletní konvence a pasti:
  [docs/architektura.md](docs/architektura.md) (*Typové entity a repositories*);
  cache číselníků [docs/analyza-ciselniky.md](docs/analyza-ciselniky.md).
  **Balíček je vlastní projekt autora** — když je potřeba změna rozhraní nebo
  nová funkce, řeš to připomínkou/issue v balíčku, ne obcházením v aplikaci.
  U částečně vyplněných (patch) entit platí: nenullable property se ptej
  nativně přes `isset()`/`??=`, nullable s patch sémantikou přes
  `Hydrator::isInitialized()`; **prázdný patch v repository** poznáš nejlevněji
  z prázdného výsledku `toData()` (extrahuje se tak jako tak), mimo hranici
  úložiště přes `getInitializationState()`. Na otázku „co entita nese“
  `toData()` naopak nepoužívej — mluví jazykem sloupců, ne domény.
- **Selection neopouští model:** repositories vracejí ven entity (`?Entity` /
  `list<Entity>`), živá `Nette\Database\Table\Selection` ani `ActiveRow`
  se smí používat jen uvnitř `app/Model/`. Presentery a šablony nikdy nedostávají
  lazy dotaz.
- **Verzuj průběžně:** commit po každém uceleném výsledku; u velkých tasků commituj
  i menší funkční celky. Commit messages anglicky.
- Tento `CLAUDE.md` udržuj aktuální — **všechny důležité poznatky o kódu/projektu zapisuj sem**
  (nebo do `docs/` linkovaných odsud), nikdy ne do osobní paměti.
