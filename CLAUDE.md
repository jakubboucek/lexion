# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> Poznámka k jazyku: dokumentace v tomto projektu se píše česky, komunikace s uživatelem probíhá česky. Anglicky zůstává pouze vše code-related (kód, komentáře v kódu, názvy proměnných/funkcí, commit messages, PR popisy). Mezi „kód" patří i **SQL migrace** ve `migrations/` — samotné DDL i komentáře v nich (`-- ...`) jsou anglicky; česky se píšou jen názvy/popisy mimo kód.

**Lexion** — scraper/checker nad českým infoSoudem: sledování soudních řízení
a notifikace o změnách. Název je slovní hříčka nad doménou `ion.cz`; produkce
poběží na **lex.ion.cz**. Kořenový adresář repa si drží historický název
`infosoud-checker` — to je záměr, nepřejmenovávat. Kompletní zadání:
[docs/zadani.md](docs/zadani.md). Plán architektury (moduly, fronta scanů, S3,
notifikace): [docs/architektura.md](docs/architektura.md).

Klíčové zjištění: nový infosoud (infosoud.gov.cz) má veřejné JSON API bez
autentizace — HTML scraping není potřeba. Popis endpointů, formát requestů,
quirky (nenalezeno jako HTTP 400) a deep-linky: [docs/infosoud-api.md](docs/infosoud-api.md).

Stav: hotový skeleton (public část, login-wall, modul Panel, DB s tabulkou `user`)
+ **první tool: parser spisovky** (`/spisovka` — parsování, validace s našeptáváním,
detekce soudu, deep-link na infosoud), číselníky soudů/rejstříků v DB a **měkká cache
řízení** (tabulka `proceeding`, JSON sloupce per zdroj; ~13 tis. řízení z ISIR výpisů,
plnění přes `bin/isir-import-listing.php` a `bin/infosoud-fetch.php` s `InfosoudClient`).
Monitoring, fronta a notifikace zatím neexistují.

**Identita spisu = pětice (soud, rejstřík, senát, číslo, ročník)** — každý senát má
vlastní číselnou řadu (ověřeno: OS Trutnov má odlišná řízení 6/7/9/30 C 1/2023)
a stejná SZ existuje i na více soudech. Nikdy nepovažuj SZ za unikátní bez soudu
a senátu.

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
  (`assets/main.js` → `assets/css/app.css`), jediné neutrální `light` téma. Aplikace je
  **záměrně utilitární** („rozhraní pro přehledné zobrazení dat"), žádná vizuálně atraktivní
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

Kroky po čerstvém klonu jsou v [README.md](README.md) (composer install, `mkdir -p web/temp
web/log`, `cp local.sample.neon local.neon`, aplikace migrací, `npm install && npm run build`,
založení uživatele přes `bin/create-user.php`).

## Adresářová struktura

**Na webhosting se nahrává pouze adresář `web/`** (jeho document root je `web/www`). Zbytek kořene
repa (CLI tooly, dev infrastruktura) na hosting nepatří, ale je dostupný v dev kontejneru.

```
infosoud-checker/           # kořen repa = celý projekt (mountuje se do /var/www/html)
├── docker-compose.yml      # jen lokální vývoj, na hosting se nenahrává
├── .docker/                # data MariaDB (gitignored), nenahrává se
├── bin/                    # CLI tooly MIMO hosting – spouští se lokálně v Dockeru
│   ├── create-user.php     # založení/aktualizace uživatele
│   ├── isir-import-listing.php  # import měsíčního výpisu ISIR do cache řízení
│   └── infosoud-fetch.php  # stažení jednoho řízení z infosoudu do cache
├── assets/                 # FRONTEND zdroje – mimo hosting, build na hostu
│   └── main.js + css/app.css     # jediný entry (Tailwind + daisyUI light)
├── migrations/structures/  # SQL migrace (aplikují se ručně)
├── node_modules/           # npm závislosti (gitignored) – mimo hosting
├── package.json            # FE závislosti a scripty (npm run dev/build) – mimo hosting
├── vite.config.ts          # konfigurace Vite – mimo hosting
└── web/                    # << TENTO adresář se nahrává na webhosting
    ├── www/                # DOCUMENT ROOT (jediná veřejně přístupná část)
    │   └── assets/         # Vite BUILD OUTPUT – VERZOVANÝ v gitu (commituje se, viz Frontend)
    ├── app/                # Nette aplikace (presentery, model, šablony) – mimo document root
    │   ├── Core/           # infrastruktura (Authenticator, RouterFactory)
    │   ├── Model/          # doménové služby a repository
    │   │   ├── Codelist/   # číselníky: CourtRepository, RegistryRepository, CourtLevel, …
    │   │   ├── Spisovka/   # SpisovkaParser (tokenizer), SpisovkaResolver (detekce soudu)
    │   │   ├── Infosoud/   # InfosoudClient (API), InfosoudLinkBuilder (deep-linky)
    │   │   └── Proceeding/ # ProceedingRepository — měkká cache řízení (JSON sloupce)
    │   └── Presentation/   # UI vrstva (viz Členění aplikace)
    ├── tests/              # nette/tester (composer tester); bootstrap + Model/*.phpt
    ├── config/             # NEON konfigurace
    ├── phpstan.neon        # PHPStan level 8 (viz Konvence)
    ├── latte-lint          # linter šablon (spouští se v kontejneru)
    ├── vendor/             # Composer závislosti (gitignored) – mimo document root
    ├── temp/               # cache (gitignored)
    └── log/                # logy (gitignored)
```

**Dvě roviny „co je kde dostupné":**
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
  jen neutrální `light` téma). Žádné oddělené public/admin bundly — celá aplikace sdílí jeden
  utilitární vzhled.
- **Build výstup:** `web/www/assets/` — **záměrně VERZOVANÝ v gitu**. Po změně čehokoli
  v `assets/` **spusť `npm run build` a výstup commitni** (jinak se rozejde se zdroji).
  `emptyOutDir: true` čistí adresář při každém buildu.
- **Node běží na HOSTU, ne v kontejneru** — devstack image je LAMP bez Node:

  ```bash
  npm install
  npm run dev      # Vite dev server + HMR
  npm run build    # produkční build do web/www/assets/
  ```

- **Napojení na PHP:** Nette Assets (`assets:` v `common.neon`) čte manifest
  z `web/www/assets/.vite/`; v šablonách `{asset 'main.js'}` (v layoutech `{asset? 'main.js'}`).
- **Tailwind scan:** Tailwind nečte Latte, jen skenuje text — `app.css` má
  `@source "../../web/app/Presentation/**/*.latte"`. Skládané názvy tříd (`text-{$x}`)
  se nedetekují — používej celé názvy.
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
- **Transformace dat:** pokud změnu nelze rozumně vyjádřit v SQL, použij analogicky **PHP soubor**
  se stejným pojmenováním (`…-popis.php`) ve stejném adresáři.
- **Spouštění:** migrace se **NEspouštějí automaticky** — vše aplikuje obsluha ručně.

## Členění aplikace a routování

Aplikace má **dvě zóny se společným utilitárním vzhledem** (daisyUI light):

| Zóna | Layout | Popis |
|------|--------|-------|
| **Veřejná část** | `Presentation/@layout.latte` | úvod, později veřejné nástroje (spisovka → odkaz, hledání soudů) |
| **Panel** (za loginem) | `Panel/@layout.latte` | modul `Panel` — sledovaná řízení, uživatelský obsah |

- **Presentery** (mapping `App\Presentation\*\**Presenter`): `Home` (dashboard s kartami
  toolů), `Spisovka` (veřejný tool `/spisovka` + JSON endpoint `validate` pro živou
  validaci), `Sign` (login/logout, mimo modul Panel — je to brána, ne chráněná stránka;
  používá veřejný layout), `Error\Error4xx`/`Error5xx`; `Panel\Dashboard` — vše v modulu
  Panel extends `Panel\BasePresenter` = login-wall (`startup()` + redirect na `:Sign:in`
  s backlink).
- **Komponenta spisovky:** `Accessory\SpisovkaInputFactory` přidá do formuláře pole
  `znacka` + select `soud`; živé chování dodává `assets/spisovka-input.js`
  (element `[data-spisovka-input]` s `data-validate-url`). Použitelné v dalších
  formulářích (watch apod.) — endpoint `Spisovka:validate` je stateless.
- **Routování** (`App\Core\RouterFactory`): `panel[/<presenter>[/<action>[/<id>]]]` → modul
  Panel (default `Dashboard:default`), pak public catch-all `[<presenter>[/<action>[/<id>]]]`
  → `Home:default`. Specifické routy (budoucí veřejná API ap.) patří **před** catch-all.
  Žádné subdomény se nepoužívají.
- **Doménové moduly** (infosoud, isir, jednání, NSS) budou v `app/Model/<Domain>/` — viz
  [docs/architektura.md](docs/architektura.md). Zatím neexistují.

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
  (nick „Claude"). **Nikdy ho nezakládej na produkci.** Když v lokální DB chybí, vytvoř ho znovu
  toolem výše.

## Deployment (FTP)

Nasazení na produkci (lex.ion.cz) řeší **dg/ftp-deployment** (nainstalovaný globálně přes
Composer **na hostu**, ne v kontejneru). Konfigurace: [.deployment.php](.deployment.php)
(nahrává jen `web/`, ignoruje dev soubory — `phpstan.neon`, `latte-lint`, `composer.json/lock`,
`config/local.neon`, `data/`, `log/`, `temp/`; `allowDelete: true` + purge `temp/cache`).
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
`list_console_messages` / `read_console_messages`). Rozšíření „Claude in Chrome" jen na výslovné
vyžádání.

**Debugging:** při chybě čti **horní výjimku** v Tracy BlueScreen (přes konzoli), ne grepem na
tipované řetězce. Pozor: v debug módu se **`BadRequestException` (404) navenek vrací jako HTTP
500** (BlueScreen); v produkci je to korektní 404 přes `Error4xx`.

**Statická analýza:** `docker compose exec -w /var/www/html/web web composer phpstan` (level 8;
šum Nette Database — magické property ActiveRow, untyped arrays v thin repositories — je ignorován
v `web/phpstan.neon`). Šablony: `docker compose exec -w /var/www/html/web web php latte-lint app`.
**Testy:** `docker compose exec -w /var/www/html/web web composer tester` (nette/tester,
`web/tests/`; čistá logika bez DB — parser apod.).

## Konvence pro Claude

- Dodržuj odlišení jazyků: **UI česky, kód anglicky** (viz výše).
- **Verzuj průběžně:** commit po každém uceleném výsledku; u velkých tasků commituj
  i menší funkční celky. Commit messages anglicky.
- Tento `CLAUDE.md` udržuj aktuální — **všechny důležité poznatky o kódu/projektu zapisuj sem**
  (nebo do `docs/` linkovaných odsud), nikdy ne do osobní paměti.
