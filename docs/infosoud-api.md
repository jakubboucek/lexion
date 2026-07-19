# infoSoud — neoficiální API (reverse-engineered)

Zjištěno 2026-07-17 průzkumem nového rozhraní. Nový infosoud běží na
**https://infosoud.gov.cz** (staré `infosoud.justice.cz` tam přesměrovává).
Frontend je Angular SPA, backend Spring Boot na `/api/v1/*` — **veřejné JSON
API bez autentizace**. Není potřeba scrapovat HTML.

⚠️ API je neoficiální (interní pro SPA) — může se kdykoli změnit bez varování.
Ukládat surové odpovědi, verzovat klienta, hlídat selhání/změnu schématu.

## Struktura SPA (analýza bundlů, 2026-07-18)

Angular SPA, esbuild chunky. **Sourcemapy nejsou** (`*.js.map` vrací HTML shell),
kód není minifikovaný co do řetězců — číselníky a i18n jdou vytěžit přímo z bundlů.
Vytěžené číselníky: [data/infosoud-ciselniky.json](data/infosoud-ciselniky.json).

- **Routy SPA** (Angular): `detail-rizeni`, `detail-udalosti`, `detail-jednani`,
  `napoveda`, `dulezite-informace`, `error`.
- **Kompletní seznam endpointů** (base `/api/v1`): `env`, `stranka/hlasky`,
  `organizace/lov`, `organizace/podrizene/lov`, `organizace/lovkod/jednaci-sin`,
  `spisova-znacka/druh/lovkod`, `rizeni/vyhledej`, `udalost/vyhledej`,
  `jednani/vyhledej` (poslední dva pod-endpointy = budoucí modul `Jednani`).
- **`typOrganizace` má jen 2 hodnoty:** `VSECHNY_KRAJE` („vrchní/krajský/okresní
  soud“) a `NEJVYSSI` („Nejvyšší soud“). **Potvrzuje naši implementaci** — pro
  KS/VS není zvláštní typ, rozlišuje se polem `okresniSoud` × `druhOrganizace`.
  Pro `NEJVYSSI` SPA nuluje `okresniSoud`/`druhOrganizace`.
- **Číselník událostí je klíčový nález:** SPA má **dvě sady názvů** —
  obecnou (OS/KS/VS) a **samostatnou pro NS** (`udalost.ns`): např. `ZAHAJ_RIZ`
  = obecně „Zahájení řízení“, u NS „Došlo Nejvyššímu soudu“; `ST_VEC_ODS` obecně
  „Skončení věci“, u NS „Datum vrácení spisu“. → promítnuto do
  `InfosoudEventType::label($code, $supreme)`. Celkem 28 obecných + 15 NS kódů
  (dřív jsme jich znali 15 posbíraných z dat).

### Nálezy vs. naše dosavadní poznatky

- ✅ **Potvrzeno:** „nenalezeno“ = `RIZENI_0000` (server template „Hledaná spisová
  značka {} pro {} neexistuje.“), `typOrganizace` 2 hodnoty, KS/VS přes
  `druhOrganizace`, detail události přes `udalost/vyhledej`.
- ❌ **Opraveno:** NS labely událostí (náš detail zobrazoval obecné) + doplněno
  ~13 chybějících kódů z autentického zdroje.
- 🆕 **Nový typ vazby — „Převedení“:** událost `PREVD_SPIS` („Převedeno“) +
  atributy `PREVD_SOUD` (navazující soud) / `PREVD_SPZN` (navazující spisová
  značka) — řízení může být **převedeno pod jinou spisovku** (ukončení + přesun).
  Další hrana pro budoucí graf souvisejících řízení (viz architektura). Rovněž
  `NAD_RIZENI` („Řízení u nadřízeného soudu“) a atribut `PO_VEC` („Navazující věc“).
- **Chybové kódy** (pro odlišení skutečné chyby od nenalezeno):
  `RIZENI_VALIDATION_0000..0006` (chyby vstupu — soud/senát/druh/číslo/ročník/
  agenda/typ organizace), `UDALOST_0000` (událost nenalezena), `RIZENI_0001`/
  `UDALOST_0001` (neočekávaná chyba), `NO_CODE`.

## Endpointy

### Číselníky (GET)

- `GET /api/v1/organizace/lov` — krajské + vrchní soudy:
  `[{"nazev":"Krajský soud Hradec Králové","kod":"KSVYCHK"}, …]`
- `GET /api/v1/organizace/podrizene/lov` — všechny okresní/obvodní soudy
  (stejný formát; kódy např. `OSVYCTU` = OS Trutnov, `OSPHA01` = ObS Praha 1).
  Query parametry zjevně ignoruje — vrací vždy vše.
- `GET /api/v1/spisova-znacka/druh/lovkod?typ=rizeni` — druhy věcí (rejstříky):
  `[{"kod":"T"},{"kod":"C"},{"kod":"NC"},{"kod":"INS"},{"kod":"EXE"}, …]`
- `GET /api/v1/env` — konfigurace prostředí pro SPA
- `GET /api/v1/stranka/hlasky?jazyk=cs-CZ&druhStranky=DULEZ_INFO` — CMS hlášky

### Vyhledání řízení (hlavní endpoint)

```
POST /api/v1/rizeni/vyhledej
Content-Type: application/json

{
  "typOrganizace": "VSECHNY_KRAJE",
  "okresniSoud": "OSVYCTU",
  "cisloSenatu": "6",
  "druhVeci": "C",
  "bcVec": "1",
  "rocnik": "2023"
}
```

Varianty requestu podle úrovně soudu (ověřeno 2026-07-17):

- **Okresní soud:** `typOrganizace: "VSECHNY_KRAJE"` + `okresniSoud: "<kod>"` (viz výše).
- **Krajský soud jako 1. instance:** `typOrganizace: "VSECHNY_KRAJE"` +
  `druhOrganizace: "<kod KS>"`, bez `okresniSoud`. Příklad: KSPH 60 INS 19742/2024 →
  `{"typOrganizace":"VSECHNY_KRAJE","druhOrganizace":"KSSTCAB","cisloSenatu":"60","druhVeci":"INS","bcVec":"19742","rocnik":"2024"}`.
- **Nejvyšší soud:** `typOrganizace: "NEJVYSSI"`, jen senát/druh/číslo/ročník
  (např. 25 Cdo 1234/2020, `druhVeci: "CDO"` — API kóduje rejstříky uppercase).

Chybný tvar requestu (neplatná kombinace polí) vrací 400 s `message:
"RIZENI_VALIDATION_0000"` — odlišné od `RIZENI_0000#…` (nenalezeno).

Odpověď (200) — kompletní řízení včetně historie událostí:

```json
{
  "cislo": 6, "druh": "C", "rocnik": 2023, "bcVec": 1,
  "nadrizenaOrganizace": "Krajský soud Hradec Králové",
  "organizace": "Okresní soud Trutnov",
  "typOrganizace": "os",
  "stav": "Odškrtnutá - evidenčně ukončená věc",
  "stavDatum": "19.04.2023",
  "udalosti": [
    {"udalost": "ZAHAJ_RIZ", "poradi": 1, "datum": "2022-12-30",
     "zruseno": false, "znackaId": {…}, "jednani": []},
    {"udalost": "NAR_JED", …},
    {"udalost": "VYD_ROZH", …}
  ]
}
```

### Detail události (předmět řízení a další atributy)

```
POST /api/v1/udalost/vyhledej
```

Tělo = stejné parametry jako u `rizeni/vyhledej` + `druhUdalosti` (kód události,
např. `ZAHAJ_RIZ`), `poradiUdalosti` (pole `poradi` z události) a `organizaceId`
(kód organizace z `udalosti[].znackaId.organizace`; u NS je to `NSJIMBM`).
Response opakuje hlavičku řízení a přidává:

- **`atributy`** — pole `{typ, hodnota}`; pozorované typy:
  - `PREDM_RIZ` — **předmět řízení** („zaplacení 4 519 Kč s příslušenstvím – tel.
    poplatky“, „Insolvenční návrh“) — bývá u ZAHAJ_RIZ na OS/KS; **VS ho nemá**,
  - `PRED_VEC` — předchozí věc („0 EPR 284088 / 2022“ — vazba mezi řízeními;
    `-` když není),
  - u NS místo toho: `SENAT`, `D_SENAT`, `SLOZENI_SENATU` (jména soudců oddělená
    `|`), `ODVOL_SOUD`, `PR_VEC_NS` (napadené rozhodnutí).
- `navazneVeci` — zatím pozorováno prázdné.

Deep-link SPA: `/InfoSoud/detail-udalosti?...&druhUdalosti=ZAHAJ_RIZ&poradiUdalosti=1&organizaceId=OSVYCTU`.
Kompletní detail řízení = **2 requesty** (řízení + první událost).

### Vazby mezi řízeními (zjištěno na 24 NC 3601/2024, OS Plzeň-město)

Tři nezávislé mechanismy vazeb:

1. **Cizí události v timeline řízení:** `udalosti[].znackaId` může ukazovat na
   **jiné řízení** — např. události `ODVOLANI` v okresním spisu nesou znackaId
   odvolacího spisu u KS (61 CO 8/2025 ap.). V timeline odvolacího spisu samotného
   přitom událost ODVOLANI není (kvirk: detail události ODVOLANI jde ale dotázat
   přes znackaId odvolacího spisu). Jeden spis může mít takových vazeb mnoho
   (NC 3601 → 8 odvolání u 61 CO).
2. **`navazneVeci` na úrovni řízení:** seznam značek souvisejících spisů
   (NC 3601 → 4× „24 P A NC“ u téhož soudu; vazba je **jednosměrná** — P a Nc
   spis zpětný odkaz nemá).
3. **Atributy detailu události:** `PRED_VEC` (předchozí věc, např. EPR před C),
   u události ODVOLANI atribut `NADRIZENY_SOUD` + `navazneVeci` s **typem**
   `SPISOVA_ZNACKA_NADRIZENEHO_SOUDU`.

Události s `zruseno: true` (zrušená jednání ap.) zůstávají v timeline — UI je má
zobrazovat odlišené (přeškrtnuté/šedé), ne skrývat.

**Pozor — quirk:** „nenalezeno“ vrací **HTTP 400** s tělem
`{"status":400,"message":"RIZENI_0000#6 C 1 / 2023#Okresní soud Trutnov",…}`.
Kód `RIZENI_0000` = řízení neexistuje; nutno odlišit od skutečné chyby requestu.

Pozorované kódy událostí: `ZAHAJ_RIZ` (zahájení), `NAR_JED` (nařízené jednání
— klíčové pro notifikace), `VYD_ROZH` (vydání rozhodnutí), `ST_VEC_VYR`,
`ST_VEC_PUK`. Událost `jednani: []` — detail jednání má zřejmě vlastní
strukturu/endpoint (záložka „Informace o jednání“ v SPA, zatím neprozkoumáno).

## Deep-linky do SPA

Detail řízení (ověřeno, funguje):

```
https://infosoud.gov.cz/InfoSoud/detail-rizeni?typOrganizace=VSECHNY_KRAJE&okresniSoud=OSVYCTU&cisloSenatu=6&druhVeci=C&bcVec=1&rocnik=2023
```

Krajský soud jako první instance (ověřeno, funguje):

```
https://infosoud.gov.cz/InfoSoud/detail-rizeni?typOrganizace=VSECHNY_KRAJE&druhOrganizace=KSSTCAB&cisloSenatu=60&druhVeci=INS&bcVec=19742&rocnik=2024
```

Další routy SPA: `detail-jednani`, `detail-udalosti`, `napoveda`, `error`.

## Omezení dat (uvádí přímo infosoud)

- Jen spisové značky okresních soudů s ročníkem > 2006 a krajských > 2007.
- Neobsahuje rozhodnutí ani údaje o účastnících — jen procesní události.
- Insolvence → ISIR (isir.justice.cz, má oficiální API), NSS → nssoud.cz.
