# infoDeska — neoficiální API (reverse-engineered)

Zjištěno 2026-08-31 analýzou zdrojových kódů SPA. Úřední desky justice běží na
**https://infodeska.gov.cz**, aplikace na base path `/eudpub/`. Frontend je
Angular SPA (esbuild, ~124 lazy chunků), backend Spring Boot — **veřejné JSON
API na `/eudpub/api/v1/*` bez autentizace**. Není potřeba scrapovat HTML.

⚠️ API je neoficiální (interní pro SPA) — může se kdykoli změnit bez varování.
Platí stejná pravidla jako u infoSoudu: ukládat surové odpovědi, hlídat změny
schématu, šetrnost k serveru.

**Proč nás zajímá:** na úředních deskách soudů visí mj. **rozvrhy práce jako
PDF s přímým downloadem** (agenda `SPR` = Správa soudu) — autoritativní zdroj
pro vytěžování senátů přes image pdftools (viz CLAUDE.md, sekce *Vytěžování
dokumentů*). Kromě toho deska nese vyvěšení s úplnými spisovými značkami
(senát/rejstřík/číslo/ročník) — potenciální další zdroj objevování spisů.

## Struktura SPA — filtry NELZE přednastavit z URL

Původní otázka zněla, jestli jde stav filtru desky zachytit do URL (stará
verze to uměla). **Nejde — ověřeno průchodem všech chunků:**

- Komponenta filtru (`app-filtr-dat`) query parametry vůbec nečte. Stav
  filtru se drží v paměti a přežívá jen přes „criteria“ breadcrumb service
  v **sessionStorage** (klíč `breadcrumbs`) — tedy jen v rámci téhož tabu.
- Jediné query parametry, které SPA kdekoli konzumuje: `token` a `vysledek`
  (aktivace účtu Moje úřední deska), `email` (přihlášení), `tab` (breadcrumb
  direktiva; stránka desky ji nevyužívá).
- Parametr `?agendy=SPR`, který se vyskytuje v kolujících odkazech, je
  **mrtvý pozůstatek staré verze** — dnešní SPA ho ignoruje.

Náhrada „přednastaveného hledání“ = zavolat přímo API (viz níže): filtr
webu je jen tenký klient nad `POST /vyveseni/vyhledej`.

## Identifikace subjektů — vlastní kódy, ≠ infoSoud

infoDeska má **vlastní číselné kódy subjektů** (`202120` = OS Rakovník),
nesouvisí s infosoudími kódy (`OSSTCRA`). Mapování na náš číselník `court`
je potřeba udělat přes seznam subjektů — detail subjektu nese `ico`, `idds`
a název, takže se dá párovat přes IČO.

Subjekty jsou dvou typů (`typSubjektu`): **`soud`** a **`zast`** (státní
zastupitelství). Seznam nese hierarchii (`kodNadrizene`,
`kodNadrizenePobocka`, flagy `nejvyssi`/`nadrizeny`) včetně NS a NSS.

## Endpointy

Base: `https://infodeska.gov.cz/eudpub/api/v1`

### Subjekty (GET)

- `GET /subjekt/vyber?typSubjektu=soud` — všechny soudy:
  `[{"kod":"202120","kodNadrizene":…,"nazev":"Okresní soud v Rakovníku",
  "nazevKratky":"OS Rakovník","nazevSubjektuVyber":"Rakovník",
  "nadrizeny":false,"nejvyssi":false}, …]`; `typSubjektu=zast` analogicky.
- `GET /subjekt/{kod}` — detail: název, `ico`, `idds`, `email`, pracoviště
  s adresami, `pobocky[]`.
- `GET /subjekt/{kod}/skupina` (+ `/skupina/{kod}`) — „skupiny vyvěšení“
  subjektu (u OS Rakovníku prázdné; SPA má pro skupinu i routu
  `skupina/:skupinaId`).

### Číselník filtrů desky

```
GET /api/v1/vyveseni/{kodSubjektu}/filtr
```

Vrací agendy a pobočky **konkrétního subjektu**, tj. jen hodnoty, které na
desce reálně jsou: `{"agendy":[{"nazev":"Správa soudu","kod":"SPR",
"kodAgendy":"SPR"}, …],"pobocky":[]}`. Pozor, `kod` ≠ vždy `kodAgendy`
(např. Občanskoprávní má `kod:"COB"`, `kodAgendy:"C"`) — do vyhledávání
se posílá `kod`.

### Vyhledání vyvěšení (hlavní endpoint)

```
POST /api/v1/vyveseni/vyhledej
Content-Type: application/json

{"kodSubjektu": "202120", "agendy": ["SPR"]}
```

- **`kodSubjektu` je povinný** — bez něj HTTP 400 (Spring validace
  `vyveseniFiltrDto`). Fulltext přes všechny desky tenhle endpoint neumí.
- **Bez stránkování** — vrací vždy kompletní seznam (celá deska OS Rakovník
  = ~1 650 záznamů v jednom poli).
- Známá kritéria (DTO `Re` ve zdrojácích SPA): `kodSubjektu`,
  `kodSupinyVyveseni` (překlep je jejich), `datumVyveseniUd`,
  `datumSveseniUd`, `datumVyveseniWeb`, `datumSveseniWeb`, `datumPlatnostOd`,
  `popis`, `celeJmeno`, `narozenDen`/`narozenMesic`/`narozenRok`,
  `cisloSenat`, `rejstrik`, `cisloBezne`, `rocnik`, `cisloList`,
  `znackaSlozena`, `agendy[]`, `pobocky[]`, `umisteni`. UI filtru posílá
  navíc i `datumPlatnostDo` a `datumSveseniWebOd`/`datumSveseniWebDo` —
  server je akceptuje.
- **Ověřené kombinace** (2026-08-31): `agendy:["SPR"]`;
  `znackaSlozena:"23 SPR 748/2025"` (celá značka v soudním pořadí
  senát-rejstřík); rozklad `cisloSenat`/`rejstrik`/`rocnik` (bez
  `cisloBezne` = celá řada); `datumPlatnostOd`/`datumPlatnostDo` ve formátu
  `YYYY-MM-DD`.
- Formát položky výsledku: `{"id":9225255,"subjekt":…,"kodSubjektu":…,
  "datumOd":"2025-12-22T13:38:42Z","popis":"Rozvrh práce …",
  "znacka":"23 SPR 748/2025","agenda":"Správa soudu","soubory":[{"id":
  "<uuid>","nazev":"…pdf","velikost":"417,47 KB","znacka":"X"}],
  "umisteni":null,"pobocka":null,"datumSveseni":"2035-12-31"}`.

### Detail a soubory

- `GET /vyveseni/detail/{id}` — navíc `puvodce` (např. `ISAS`), `duvod`
  a plné rozlišení datumů: `vyveseniUd`/`sveseniUd` (úřední deska) vs.
  `vyveseniWeb`/`sveseniWeb` (web) a `platneOd`.
- `GET /vyveseni/soubor/{idSouboru}/download` — přímé stažení souboru
  (`idSouboru` = UUID z pole `soubory[]`).
- `POST /vyveseni/lustruj` — druhý vyhledávací endpoint (stejné DTO;
  ve stažených chuncích nemá konzumenta, zřejmě lustrace osoby přes
  `celeJmeno`/`narozen*` pro Moje úřední deska — nezkoumáno).

### Deep-linky webu (pro lidi)

- Deska subjektu: `https://infodeska.gov.cz/eudpub/uredni-deska/organizace/{kod}`
- Detail vyvěšení: `…/uredni-deska/organizace/{kod}/vyveseni/{id}`

## Pasti a quirky

- **Stejná značka může viset vícekrát** — každé vyvěšení je samostatný
  záznam s vlastním `id` (23 SPR 748/2025 visí 2× s odstupem půl roku:
  rozvrh a jeho změna). Klíčem záznamu je `id` vyvěšení, ne značka.
- **`sveseniWeb` v roce 2035 ≈ „nikdy“** — rozvrhy práce mají svěšení
  nastavené 10 let dopředu.
- `velikost` souboru je český formátovaný string („417,47 KB“), ne číslo.
- `datumOd` v seznamu odpovídá `vyveseniWeb` z detailu (okamžik publikace
  na web), ne datu vyvěšení na fyzické desce (`vyveseniUd`).
- Chyby chodí ve Spring Boot formátu (`{"timestamp":…,"status":400,
  "error":"Bad Request","message":"Validation failed…","path":…}`).
- Značky na desce píše soud v pořadí **senát rejstřík číslo/ročník**
  („23 SPR 748/2025“) — shodné s naším značkovým řádem, rejstřík SPR ale
  v našem číselníku `registry` není (správní agenda soudu, ne soudní spis).
