# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> Poznámka k jazyku: dokumentace v tomto projektu se píše česky, komunikace s uživatelem probíhá česky. Anglicky zůstává pouze vše code-related (kód, komentáře v kódu, názvy proměnných/funkcí, commit messages, PR popisy). Mezi „kód“ patří i **SQL migrace** ve `migrations/` — samotné DDL i komentáře v nich (`-- ...`) jsou anglicky; česky se píšou jen názvy/popisy mimo kód.

**Lexion** — scraper/checker nad českým infoSoudem: sledování soudních řízení
a notifikace o změnách. Název je slovní hříčka nad doménou `ion.cz`; produkce
poběží na **lex.ion.cz**. Repo je na GitHubu: **github.com/jakubboucek/lexion**
(remote `origin`; issues se evidují tamtéž přes `gh issue …`). Kořenový adresář repa si drží historický název
`infosoud-checker` — to je záměr, nepřejmenovávat. Kompletní zadání:
[docs/zadani.md](docs/zadani.md). Popis existující architektury (moduly, cache,
číselníky, pravidla načítání): [docs/architektura.md](docs/architektura.md).
Cíle, plány a designové úvahy budoucího rozvoje (monitoring, fronta scanů, S3,
notifikace, Tool 2…): [docs/roadmap.md](docs/roadmap.md) — plány patří tam,
popis stavu do architektury/sem. Evidence technologického dluhu z auditu kódu
(odbavuje se postupně, položky odškrtávat): [docs/tech-debt.md](docs/tech-debt.md).
**Probíhající převod na typové entity** (stav, konvence entit/repositories,
plán dalších kol): [docs/entity-refactoring.md](docs/entity-refactoring.md).

Klíčové zjištění: nový infosoud (infosoud.gov.cz) má veřejné JSON API bez
autentizace — HTML scraping není potřeba. Popis endpointů, formát requestů,
quirky (nenalezeno jako HTTP 400) a deep-linky: [docs/infosoud-api.md](docs/infosoud-api.md).
Analýza detailu událostí, (ne)robustnosti `poradi` a návrh rozpadu JSON cache
do tabulek `proceeding_event`/`proceeding_relation`: [docs/analyza-udalosti.md](docs/analyza-udalosti.md).
Číselníkové paradigma — rozhodnutí o cache číselníků (`court`/`registry`/`court_prefix`/
`relation_type`: serializovaný snapshot entit s mapami přes nette/caching) a kontrakt
pro entity refactoring: [docs/analyza-ciselniky.md](docs/analyza-ciselniky.md).

Stav: hotový skeleton (public část, login-wall, modul Panel, DB s tabulkou `user`)
+ **tool parser spisovky** (na úvodní stránce — parsování, validace s našeptáváním,
detekce soudu, deep-link na infosoud), **tool detail spisu** (`/spis/<soud>/<slug>` — cache-first,
max 2 requesty na justici, timeline událostí, související řízení jen jako odkazy),
číselníky soudů/rejstříků v DB a **měkká cache řízení** (tabulka `proceeding`, JSON
sloupce per zdroj; ~13 tis. řízení pochází z jednorázového importu ISIR výpisů — importní
tool byl po splnění účelu odstraněn, plnění dnes: `bin/infosoud-fetch.php` s `InfosoudClient`
a samotný web) + **projekční tabulky událostí a vazeb**
(`proceeding_event`/`proceeding_relation` + číselník `relation_type`; staví je
`ProceedingProjectionService` z raw JSON při syncu), **detail události** (viz presenter
`Spis`) a **oblíbené spisy** (tabulky `favorite`/`favorite_group`, hvězdička s modaly na
detailu spisu, přehled se skupinami a ručním řazením na Panel Dashboardu — viz sekce
*Oblíbené spisy*) a **evidence jednání z infoJednání** (tabulky `hearing`/`hearing_observation`
+ číselník síní `hearing_room`; sken `bin/infojednani-scan.php` → import
`bin/infojednani-import.php`; ~36 tis. jednání za 30denní okno — viz
[docs/infojednani-api.md](docs/infojednani-api.md)). Monitoring, fronta a notifikace zatím
neexistují. Vazbu jednání na `proceeding` páruje `bin/hearing-bind.php` ve dvou fázích:
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
a senátu.

**Ročník je interně vždy čtyřmístný** (1961, 2024) — v `Spisovka`, ve všech sloupcích DB
i v našich URL (slug je na 4 číslice striktní, dvoumístné URL se odmítají). Justice ale
u **stále živých spisů z 20. století** používá dvoumístný ročník („0 P 480/**61**“), takže
na hranicích se převádí přes `App\Model\Spisovka\CaseYear`: `fromUserInput()` (pivot dle
aktuálního roku, odmítá budoucnost), `fromUpstream()` (data z API — dvojčíslí **vždy** 19xx,
bez pivotu), `forApi()` (strip na dvojčíslí) a `forDisplay()` (tvar, jak píše soud).
**Raw JSON sloupce zůstávají nedotčené** — každé čtení `rocnik` z nich musí projít
`fromUpstream()`. Detaily a past „2098 vrátí spis z 1998“: [docs/infosoud-api.md](docs/infosoud-api.md).

## Terminologie a pojmenování (závazné konvence)

- **Data v `proceeding` NEJSOU cache — koncepčně je to „spisovna“**
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
- **Pojmenování nových objektů** (rozhodnutí 2026-07-27, upřesněno
  2026-07-28): plošné přejmenování „Spisovka“ je odloženo, ale **nové**
  třídy/objekty už vznikají s cílovými názvy — **`CaseFile`** pro spis
  (holé `Case` nejde, je to rezervované slovo PHP; navazuje na zavedené
  `CaseYear`/`CaseSummaryService`/`caseChip`), **`CaseQuery`** výhradně pro
  **hledání spisů** (formulář na HP, kladení dotazů), **`Document`**
  rezervováno pro budoucí nahrávané soubory (PDF rozsudky ap.) — těm se
  nikdy neříká „file“, aby nekolidovaly se spisem. Cílový název DB tabulky
  je `case_file` (+ FK `case_file_id`, odvozené tabulky obdobně) — **rename
  tabulek se dělá samostatnou vlnou až po dokončení typového refactoringu**
  (rozhodnutí 2026-08-05), ne spolu s ním. Nové objekty a reference už ale
  cílový název nesou (entity `CaseFile`/`CaseFileEvent`/`CaseFileRelation`,
  property `caseFileId`, metody `findByCaseFile()`); existující třídy
  `Spisovka*`/`Proceeding*` si starý název nechávají do té vlny.

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
infosoud-checker/           # kořen repa = celý projekt (mountuje se do /var/www/html)
├── docker-compose.yml      # jen lokální vývoj, na hosting se nenahrává
├── .docker/                # data MariaDB (gitignored), nenahrává se
├── bin/                    # CLI tooly MIMO hosting – spouští se lokálně v Dockeru
│   ├── create-user.php     # založení/aktualizace uživatele
│   ├── infosoud-fetch.php  # stažení jednoho řízení z infosoudu do cache
│   ├── infosoud-fetch-hearings.php  # detaily jednání (JED_*) řízení z infosoudu
│   ├── infojednani-scan.php # sken všech síní × dnů z infoJednání do .data/
│   ├── infojednani-import.php # import skenu do tabulek hearing*
│   └── hearing-bind.php    # párování hearing ↔ proceeding (guess/confirm, --dry-run)
├── assets/                 # FRONTEND zdroje – mimo hosting, build na hostu
│   └── main.js + css/app.css     # jediný entry (Tailwind + daisyUI light/dark);
│                           #   main.js importuje moduly spisovka-input.js, dialog.js,
│                           #   copy-button.js (kopírování spisovky) a strip-tracking-url-params.js
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
    │   │   └── Proceeding/ # ProceedingRepository — měkká cache řízení (JSON sloupce); CaseSummaryService (předmět/stav z cache)
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

- **Tom Select + nette-forms:** select soudu ve formuláři spisovky je vyhledávací
  combobox přes **Tom Select** (npm závislost; `app.css` importuje jeho CSS a přebíjí
  ho na daisyUI vzhled — je to největší kus vlastního CSS v projektu). Klientskou
  validaci formulářů dodává npm balíček **`nette-forms`** (`netteForms.initOnLoad()`
  v `main.js`).

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

Aplikace má **dvě zóny se společným utilitárním vzhledem** (daisyUI light/dark) a **jediným
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
  `/spis/<soud>/<znacka>/udalost/<id>` — `id` je náš PK v `proceeding_event`, ne upstream
  `poradi`; timeline a související řízení se čtou z projekčních tabulek `proceeding_event`/
  `proceeding_relation` (plní je `ProceedingProjectionService` při každém syncu, vazby
  obousměrně přes reverzní labely číselníku `relation_type`), detail události se dočítá
  lazy (thin/full řádky, cooldown 5 min) a nesoulad typu/data s API spouští integritní
  flow — flash + redirect na spis s výzvou k aktualizaci (pozor: aktualizace zatím
  projekci jen upsertuje, zahození a přegenerování paměti událostí není zapojené —
  viz TODO v [docs/analyza-udalosti.md](docs/analyza-udalosti.md)); u NAR_JED se
  z detailu parsuje jednání (`InfosoudHearing` — čas/síň/druh z `JED_*` atributů,
  dočasné řešení než bude samostatný scraping jednání), timeline ho zobrazuje pod
  názvem události, nenačtené nabízí tlačítko „Stáhnout podrobnosti“ (signál
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
  `ProceedingSyncService`, ruční refresh signálem s 5min cooldownem, stale banner po
  **1 měsíci** (`StaleThreshold` — kratší práh byl otravný, spisy se reálně mění
  spíš v řádu měsíců); `/spis/` je v robots.txt disallow), `Sign` (login/logout, mimo modul Panel —
  je to brána, ne chráněná stránka), `Error\Error4xx`/`Error5xx`;
  `Panel\Dashboard` (přehled oblíbených spisů, viz *Oblíbené spisy*) — vše v modulu Panel
  extends `Panel\BasePresenter` = login-wall (`startup()` + redirect na `:Sign:in` s backlink).
- **Komponenta spisovky:** `Accessory\SpisovkaInputFactory` přidá do formuláře pole
  `znacka` + select `soud`; živé chování dodává `assets/spisovka-input.js`
  (element `[data-spisovka-input]` s `data-validate-url`). Použitelné v dalších
  formulářích (watch apod.) — endpoint `Spisovka:validate` je stateless.
  Validace jede v režimu „reward early, punish late“: u nedotčeného pole se při
  psaní ukazují jen pozitivní zprávy (Rozpoznáno, určení soudu), chyby až po
  opuštění pole / submitu; po první zobrazené chybě se přepne do plně živého
  režimu. Validace navíc hledá spis v cache `proceeding`
  (`ProceedingRepository::findBySpisovka`, index `idx_proceeding_spisovka`):
  jediná shoda soud **předvybere** (nikdy nepřepíše ruční volbu uživatele
  a nabídku soudů neomezuje — cache není autoritativní), víc shod jen vypíše
  seznam soudů; stejný fallback běží i na serveru při submitu bez vybraného
  soudu. **Druhý zdroj kandidátů = jednání** (`HearingRepository::countPerVenueBySpisovka`,
  index `ix_hearing_spisovka`) — uplatní se, jen když cache mlčí, protože jde
  o **soud síně**, ne nutně domovský soud spisu; texty proto říkají „evidujeme
  jednání s touto značkou“, nikdy „spis je veden u…“. Pořadí: rozpoznání ze
  značky → cache `proceeding` → jednání. Tlačítko „Otevřít“ před redirectem ověří existenci řízení
  (cache → jinak fetch z infosoudu, který rovnou naplní cache — detail se pak
  odbaví bez dalších requestů); neúspěch zůstává na formuláři jako form-level
  chyba. „InfoSoud“ zůstává tupý překladač URL bez ověřování.
- **Routování** (`App\Core\RouterFactory`): `panel[/<presenter>[/<action>[/<id>]]]` → modul
  Panel (default `Dashboard:default`), pak specifické routy `spis/<soud>/<znacka>/udalost/<id>`,
  `spis/<soud>/<znacka>` a `o-projektu`, nakonec public catch-all
  `[<presenter>[/<action>[/<id>]]]` → `Home:default`. Specifické routy (i budoucí veřejná
  API ap.) patří **před** catch-all. Žádné subdomény se nepoužívají.
- **Doménové moduly** v `app/Model/<Domain>/` — viz
  [docs/architektura.md](docs/architektura.md): `Infosoud` a `Hearing` (jednání) už
  existují, `Isir` a `Nss` zatím ne (ISIR data v `proceeding.isir_json` pocházejí
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

- **Datový model:** `favorite` (user × proceeding, unikátní pár; volitelný vlastní `name`,
  `group_id` NULL = obecný seznam, `position` = ruční pořadí v rámci bucketu) a
  `favorite_group` (per-user skupiny, ruční pořadí). FK na `proceeding` **záměrně bez
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
  se sloupci vlastní název + spisovka, předmět, stav řízení (obojí z `CaseSummaryService`);
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
500** (BlueScreen); v produkci je to korektní 404 přes `Error4xx`.

**Statická analýza:** `docker compose exec -w /var/www/html/web web composer phpstan` (level 8
nad `app/`, `../bin` i `../migrations/data`; šum Nette Database — magické property ActiveRow,
untyped arrays v thin repositories — je ignorován v `web/phpstan.neon`). Šablony:
`composer latte-lint`. **Testy:** `composer tester` (nette/tester, `web/tests/`; převážně
čistá logika bez DB; testy bootující DI a čtoucí DB — `RegistryCodelistConsistency`,
`SpisovkaSlugParser` — se bez dostupné DB samy skipnou). **Vše najednou:**
`docker compose exec -w /var/www/html/web web composer check` (phpstan + latte-lint + tester).

## Konvence pro Claude

- Dodržuj odlišení jazyků: **UI česky, kód anglicky** (viz výše).
- **Latte šablony deklarují vstupy:** na začátku šablony výčet proměnných předávaných
  z presenteru značkou `{varType}`. Latte má omezenou syntaxi typů — generika typu
  `array<int, string>` nezná; používej `Type[]`, `?Type`, `array`; třídy z globálního
  namespace (`stdClass`) potřebují úvodní `\`, jinak je PhpStorm nebere jako typ.
  **Výchozí proměnné Nette se nedeklarují** (`$basePath`, `$baseUrl`, `$user`,
  `$presenter`, `$control`, `$flashes` — PhpStorm je zná jako předdefinované);
  `$form` deklaruje šablona s formulářem.
- **Typové entity (probíhá):** nové a převáděné domény používají
  **`jakubboucek/hydrator`** (registrovaná `HydratorFactory`, formát
  `NetteDatabase`, app TZ Europe/Prague). Entita = typované public properties
  + marker interface `Entity`, bez atributů a konstruktoru; repository vrací
  entity a bere je i na zápisu (částečně vyplněná entita = patch). Hotovo:
  `Model/User/`, `Model/Favorite/`, `Model/Hearing/`, `Model/Proceeding/`
  (entity `CaseFile`, `CaseFileEvent`, `CaseFileRelation` — **tabulky zůstávají
  `proceeding*`**, DB vlna přejmenování přijde samostatně) a číselník `relation_type`;
  **odloženo** je `court`/`registry`/`court_prefix`/`senate_rule` — čekají na
  rozhodnutí, jak držet číselníky v paměti (dnes dělají 70 % dotazů stránky).
  Enum se zavádí jen tam, kde množinu hodnot drží i DB (CHECK). Konvence,
  stav a plán: [docs/entity-refactoring.md](docs/entity-refactoring.md).
  **Balíček je vlastní projekt autora** — když je potřeba změna rozhraní nebo
  nová funkce, řeš to připomínkou/issue v balíčku, ne obcházením v aplikaci.
- **Selection neopouští model:** repositories vracejí ven `list<ActiveRow>`
  (u převedených domén rovnou entity), živá `Nette\Database\Table\Selection`
  se smí používat jen uvnitř `app/Model/`. Presentery a šablony nikdy nedostávají
  lazy dotaz.
- **Verzuj průběžně:** commit po každém uceleném výsledku; u velkých tasků commituj
  i menší funkční celky. Commit messages anglicky.
- Tento `CLAUDE.md` udržuj aktuální — **všechny důležité poznatky o kódu/projektu zapisuj sem**
  (nebo do `docs/` linkovaných odsud), nikdy ne do osobní paměti.
