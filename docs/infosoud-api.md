# infoSoud — neoficiální API (reverse-engineered)

Zjištěno 2026-07-17 průzkumem nového rozhraní. Nový infosoud běží na
**https://infosoud.gov.cz** (staré `infosoud.justice.cz` tam přesměrovává).
Frontend je Angular SPA, backend Spring Boot na `/api/v1/*` — **veřejné JSON
API bez autentizace**. Není potřeba scrapovat HTML.

⚠️ API je neoficiální (interní pro SPA) — může se kdykoli změnit bez varování.
Ukládat surové odpovědi, verzovat klienta, hlídat selhání/změnu schématu.

## Struktura SPA (zjištěno 2026-07-18)

Frontend je Angular SPA nad JSON API `/api/v1/*`. Číselníky a i18n texty, které
SPA používá, máme zachycené v [data/infosoud-ciselniky.json](data/infosoud-ciselniky.json).

- **Routy SPA** (Angular): `detail-rizeni`, `detail-udalosti`, `detail-jednani`,
  `napoveda`, `dulezite-informace`, `error`.
- **Kompletní seznam endpointů** (base `/api/v1`): `env`, `stranka/hlasky`,
  `organizace/lov`, `organizace/podrizene/lov`, `organizace/lovkod/jednaci-sin`,
  `spisova-znacka/druh/lovkod`, `rizeni/vyhledej`, `udalost/vyhledej`
  (= `InfosoudClient::fetchEventDetail()`), `jednani/vyhledej` (jednání —
  reálně se skenují přes samostatné API infoJednání, viz sekce *Jednání* níže
  a [infojednani-api.md](infojednani-api.md)).
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

#### Povolené rejstříky (`druhVeci`) podle úrovně soudu (zjištěno 2026-08-25)

Nápověda webového formuláře infosoudu deklaruje whitelist rejstříků, na které
se lze ptát, per úroveň soudu:

- **OS:** T, C, P a Nc, D, E, P, Nc („pouze Exekuční a Opatrovnické“), ERo,
  Ro, EC, EVC, EXE, EPR, PP
- **KS/VS:** Cm, Sm, Ca, Cad, Az, T, To, Nt, Nc, C, Co, Ntd, Cmo, Ko, Nco,
  Ncd, Ncp, EC, ECm, ICm, INS, K, Kv, EVCm, A, Ad, Af, Na, EPR, UL
- **NS:** Cdo, Odo, Tdo, Tz, Ncu

Nepovolený `druhVeci` vrací **HTTP 400 `RIZENI_VALIDATION_0002`** (druhá
pozice validace = druh věci); SPA k tomu říká „Chybný zápis spisové značky,
druhý parametr (druh věci) nemá povolenou hodnotu.“ Je to třetí druh 400
odpovědi vedle `RIZENI_0000#…` (nenalezeno) a `RIZENI_VALIDATION_0000`
(chybná kombinace polí).

**Whitelist z nápovědy ale není přesný** — empirie z hromadného fetche
2026-08-25:

- ❌ **Tm u OS** — odmítnuto (4 spisy OSZPCPM, `RIZENI_VALIDATION_0002`),
- ❌ **Tmo u KS** — odmítnuto (KSSCEUL 5 Tmo 23/2026), v deklarovaném
  KS/VS whitelistu ani není,
- ✅ **Nt u OS** — **funguje** (16 spisů OSZPCPM + OSPHA08 úspěšně staženo),
  přestože v deklarovaném OS whitelistu chybí.

**Nc u KS/VS: jiný kód, jiná příčina** (zjištěno 2026-08-27). Rejstřík `Nc`
je v deklarovaném KS/VS whitelistu, přesto ho API u těchto soudů odmítá —
a to kódem **`RIZENI_VALIDATION_0005`** („Byla zadaná chybná **agenda**“),
ne `_0002` jako mládežnické rejstříky. Ověřeno na třech krajských soudech
(KSSCEUL, KSSEMOS, KSSTCAB) i na VS Praha; u okresních soudů a u NS tentýž
rejstřík validací **projde** (vrátí `RIZENI_0000` = nenalezeno), stejně jako
sousední `Nco`/`Ncd`/`Ncp` u KS. Odmítnutí je tedy vlastností **dvojice
(rejstřík, úroveň soudu)**, ne rejstříku samotného — nesmí se proto hlásit
už při validaci značky, dokud není znám soud.

Interpretace: spolehlivě odmítané jsou rejstříky **soudnictví ve věcech
mládeže** (Tm, Tmo, patrně i Ntm — zatím neověřeno), což dává smysl jako
záměrné vyloučení (ochrana mladistvých, zákon č. 218/2003 Sb.), zatímco
deklarovaný whitelist je jen nepřesný opis — v obou směrech (chybí v něm
funkční Nt u OS, naopak slibuje nefunkční Nc u KS/VS). Pro validaci na naší
straně proto **nepoužívat whitelist z nápovědy**, ale úzké empirické
blocklisty (`InfosoudQueryPolicy`): plošný pro mládežnické rejstříky a
per-úroveň pro Nc; ostatní kombinace nechat na API — nepovolený rejstřík
skončí čitelnou chybou, ne špatnými daty.

**Validační kód ≠ výpadek.** Odpověď `*_VALIDATION_*` znamená, že dotaz je
vadný, ne že služba nefunguje; opakování nepomůže. Klient ji proto hází jako
`InfosoudRejectedException` (potomek `InfosoudApiException`), sync ji
překládá na `CaseLoadOutcome::Rejected` a UI říká „infoSoud tuto značku
u tohoto soudu nevyhledává“ místo „momentálně nedostupný“. V našich
datech se blokované kombinace vyskytují jen v `hearing` (Tm u OS: 347 řízení,
Tmo u KS: 1) — jednání mladistvých infoJednání vydává, jen infosoud k nim
odmítá detail řízení.

**Implementováno (2026-08-25):** blocklist drží `InfosoudQueryPolicy`
(statická třída, norm formy `TM`/`TMO`/`NTM`) a vynucuje se na dvou místech:
`SpisovkaResolver` přidá chybu validace (pokryje živou validaci i submit
formuláře — resolve odmítá při jakékoli chybě rezoluce) a `InfosoudClient`
odmítne `fetchCase`/`fetchEventDetail` výjimkou ještě před HTTP requestem
(backstop pro CLI tooly a sync). Test: `web/tests/Model/InfosoudQueryPolicy.phpt`.

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
    {"udalostId": null, "udalost": "ZAHAJ_RIZ", "poradi": 1, "datum": "2022-12-30",
     "zruseno": false, "znackaId": {…}, "jednani": []},
    {"udalost": "NAR_JED", …},
    {"udalost": "VYD_ROZH", …}
  ]
}
```

**Sémantika identifikace a řazení událostí** (zjištěno 2026-07-19, detailně
[analyza-udalosti.md](analyza-udalosti.md)):

- `poradi` = pořadové číslo záznamu ve spisu na straně soudu (ISAS), s dírami
  (neveřejné záznamy čísla spotřebují). Je to **pořadí zápisu, ne pořadí
  událostí v čase** (NAR_JED má číslo z doby nařízení, datum budoucí). Může se
  časem přečíslovat — nepovažovat za stabilní klíč.
- Cizí události (ODVOLANI ap.) nesou `poradi` z číselné řady **cizího spisu**
  (`znackaId`).
- `udalostId` je `null` u ISAS soudů; vyplněné jen u EPR (CEPR backend,
  globální ID, i složené „12956732;186“). Lookup detailu stejně jede přes
  (spis, druhUdalosti, poradiUdalosti).
- Pole `udalosti` je řazené podle data, ale **v rámci dne nahodile** — SPA to
  nijak nesortuje. Správný tie-break v rámci dne = `poradi` (jen mezi
  vlastními záznamy spisu).
- Nápověda SPA tvrdí, že data ze soudů se propisují **1× denně** — empiricky
  to ale **neplatí** (např. zrušení jednání se objevuje kdykoli během dne;
  dlouhodobé pozorování). Kadenci změn pro monitoring vypozorovat z vlastních
  dat, na deklaraci nespoléhat.

### Detail události (předmět řízení a další atributy)

```
POST /api/v1/udalost/vyhledej
```

Tělo = stejné parametry jako u `rizeni/vyhledej` + `druhUdalosti` (kód události,
např. `ZAHAJ_RIZ`), `poradiUdalosti` (pole `poradi` z události) a `organizaceId`
(kód organizace z `udalosti[].znackaId.organizace`; u NS je to `NSJIMBM`).

**Quirk (zjištěno 2026-07-20 na 0 EPR 78221/2026, OS Plzeň-město):** u spisů
s CEPR backendem (rejstřík EPR) endpoint **vyžaduje i `udalostId`** (hodnota
z timeline, bývá i složená — „17614149;217“); bez něj vrací HTTP 400
`UDALOST_0001` pro *všechny* události spisu (tedy vůbec nejde dotáhnout ani
předmět řízení ze ZAHAJ_RIZ). U ISAS soudů je `udalostId` v timeline `null`
a lookup jede čistě přes (druh, poradi). `InfosoudClient::fetchEventDetail`
proto `udalostId` posílá vždy, když ho známe (`case_file_event.upstream_id`).
Pozor na odlišnost message kódů: „událost nenalezena“ je `UDALOST_0000`,
chybějící `udalostId` u CEPR je `UDALOST_0001`.

**`druhUdalosti` endpoint nevaliduje — je to echo** (ověřeno 2026-08-23 na
10 T 3/2026 MS Praha): záznam se hledá **jen podle (spis, poradiUdalosti)**
a `typUdalosti` v odpovědi pouze zrcadlí poslaný druh — i nesmyslný
`VYD_ROZH` vrátí `JED_*` atributy jednání s `typUdalosti=VYD_ROZH`.
Důsledek: shoda typu odpovědi s očekáváním nic neověřuje (integrita se musí
opírat o datum, viz [analyza-udalosti.md](analyza-udalosti.md)). A dále:
**neexistující `poradi` nemusí vrátit `UDALOST_0000`** — pozorována i HTTP
200 **prázdná obálka** (echo typu, `datumUdalost` chybí, `atributy`
prázdné).

Response opakuje hlavičku řízení a přidává:

- **`atributy`** — pole `{typ, hodnota}`; pozorované typy:
  - `PREDM_RIZ` — **předmět řízení** („zaplacení 4 519 Kč s příslušenstvím – tel.
    poplatky“, „Insolvenční návrh“) — bývá u ZAHAJ_RIZ na OS/KS; **VS ho nemá**,
  - `PRED_VEC` — předchozí věc („0 EPR 284088 / 2022“ — vazba mezi řízeními;
    `-` když není),
  - u NS místo toho: `SENAT`, `D_SENAT`, `SLOZENI_SENATU` (jména soudců oddělená
    `|`), `ODVOL_SOUD`, `PR_VEC_NS` (napadené rozhodnutí).
- `navazneVeci` — zatím pozorováno prázdné.

Deep-link SPA: `/InfoSoud/detail-udalosti?...&druhUdalosti=ZAHAJ_RIZ&poradiUdalosti=1&organizaceId=OSVYCTU`
— resolver SPA posílá query parametry **1:1 do API**. U cizí události SPA
přidává `cisloSenatuId`/`druhVeciId`/`bcVecId`/`rocnikId` (jen hodnoty lišící
se od mateřského spisu) + `udalostId`; detail cizí události jde ale načíst
i tak, že se cizí spis pošle jako hlavní parametry (ověřeno na ODVOLANI).
Kompletní detail řízení = **2 requesty** (řízení + první událost).

Atributy per typ události (ověřeno na vzorcích 2026-07-19; labely včetně
NS/KS overridů v [data/infosoud-ciselniky.json](data/infosoud-ciselniky.json),
tabulka typů v [analyza-udalosti.md](analyza-udalosti.md)): NAR_JED/ZRUS_JED
nesou `JED_*` (síň, druh, začátek s časem, výsledek), VYD_ROZH `ROZH_*`,
POD_OP_PR/VYR_OP_PR `OP_*`, ODES_SPIS/VRAC_SPIS `OD_SP_*`/`VR_SP_*`,
ST_VEC_* `STAV_VECI`+`ST_VEC_D_D`, PREVD_SPIS `PREVD_*`. Pozor na flag
atribut `NAVRH_PR` (SPA zobrazuje jen label, a jen při hodnotě `#TRUE`)
a na hodnoty s `|` (SLOZENI_SENATU — SPA dělá split/join na čárky).
`JED_*` atributy z NAR_JED/ZRUS_JED parsuje `InfosoudHearing` (čas/síň/druh/
výsledek) — timeline je zobrazuje pod událostí a `bin/hearing-bind.php` je
používá jako **korroborační zdroj** k potvrzování vazby jednání z infoJednání
na řízení (shoda `JED_D_ZAC` + `JED_SIN` → `court_binding = 'confirmed'`);
hromadně je dotahuje `bin/infosoud-fetch-hearings.php`. **`JED_SIN` může
nést i hodnotu `nerezervováno`** (zjištěno 2026-08-23 na 3 To 27/2026
VS Praha: usnesení, začátek 14:43 = spíš čas zápisu než slot — úkon bez
rezervované síně, typicky rozhodnutí v neveřejném zasedání). Původ hodnoty
je **nerozhodnutý**: buď placeholder rezervačního modulu, nebo volný text
zapsaný pracovníkem soudu jako „místnost" — z našich dat to odlišit nejde
(v žádném ze tří snapshotů číselníku síní se nevyskytuje, ale pokud je
číselník agregát nařízených jednání — viz hypotéza
v [infojednani-api.md](infojednani-api.md) — položka z minulosti by v něm
stejně nebyla). Prakticky: na tuto hodnotu nikdy nenaváže `hearing_room`
ani bind.

### Vícetermínová jednání: pole `jednani[]` u NAR_JED/ZRUS_JED (zjištěno 2026-08-23)

Timeline **agreguje více záznamů jednání pod jedinou událost** NAR_JED či
ZRUS_JED s vnořeným polem `jednani[]` — ostatní záznamy nesou vlastní
`poradi`, ale **v `udalosti[]` se jako samostatné události NEVYSKYTUJÍ**:

```json
{"udalost": "NAR_JED", "poradi": 59, "datum": "2026-08-17",
 "jednani": [{"datum": "2026-08-18", "poradiUdalosti": 58}]}
```

Vnořený záznam nese **vždy jen** `{datum, poradiUdalosti}` (řazeno datem
vzestupně), nic víc — žádný čas, síň ani vlastní `zruseno`. Nejčastější
případ je vícedenní hlavní líčení (pokračovací dny), ale sémantika je širší:

- **V tentýž den může být víc záznamů** (51 T 5/2023 KS Ústí: zrušený
  NAR_JED poradi 7 nese 11 vnořených záznamů po ~3 na den) a **ZRUS_JED
  agreguje zrušené záznamy napříč daty i bloky** (tamtéž: ZRUS_JED poradi 6
  z 19. 1. 2024 nese 3 záznamy z 19. 1. a 4 z 18. 4. 2024).
- **Ruší se po jednotlivých záznamech, ne jen celý blok** — vnořené záznamy
  jednoho agregátu mají různé výsledky (`JED_VYSLED = ODROČENO` vs. jen
  `JED_ZRUS = Ano`). Částečně zrušený blok (rodič `zruseno: false`, jeden
  termín zrušený) zatím v datech zachycen není; vnořený záznam ale vlastní
  `zruseno` nenese, takže **individuální zrušení termínu z timeline nejde
  poznat** (bylo by vidět až v `JED_ZRUS` detailu) — UI to přiznává
  v tooltipu příznaku „vícedenní“.
- **U ZRUS_JED je `datum` (vnořené i top-level) datum úkonu zrušení, ne den
  jednání** — skutečný den nese až `JED_D_ZAC` detailu (`JED_D_Z_V` = datum
  odvolání jednání). Záznam 14 z 51 T 5/2023: `datum` 19. 1. 2024, ale
  `JED_D_ZAC` 18. 4. 2024. U NAR_JED větve `datum` dnu jednání odpovídá.
- Ověřeno na `10 T 3/2026` MS Praha (velký trestní proces: 15 z 28 událostí
  timeline má `jednani[]`, dohromady 19 vnořených záznamů), dále
  `45 T 9/2022` KS Ostrava, `51 T 5/2023` KS Ústí, `8 To 35/2024` KS Plzeň.
  Plošné stažení spisovny (2026-08-26, ~22 tis. řízení s infosoud daty)
  původní hypotézu „jen trestní věci krajské úrovně“ vyvrátilo: 80 spisů
  s 2 423 termíny, převážně T u KS/MS (67 spisů), ale i **CO** (odvolací
  civil, 6), **A** (správní, 2), **CM/CMO** (obchodní) a **To u VS Praha** —
  jde o obecný mechanismus rezervačního modulu, ne specialitu trestu.
  Extrém: `64 A 1/2025` KS Brno — NAR_JED s **369 vnořenými denními
  termíny** (rozsah 1,5 roku, poradi až ~1120) hromadně zrušený ZRUS_JED
  agregátem s 369 záznamy, všechny s datem úkonu 2025-04-04.
- **Detail vnořeného záznamu JE stažitelný** přes `udalost/vyhledej`
  s `poradi=<poradiUdalosti>` (na `druhUdalosti` nezáleží — je to echo, viz
  výše) — vrací plné `JED_*` atributy včetně vlastní síně a času; termín
  může mít **jinou síň** než první den (poradi 58: síň 301, zatímco
  #59/17. 8. bývala 114).
- InfoJednání vnořené termíny normálně zobrazuje jako samostatná denní
  jednání (`hearing` je má, např. 6. 8., 12. 8., 18. 8. u 10 T 3/2026).
- Sondy: `.data/test-continuation-detail.php`,
  `.data/test-zrusjed-continuation.php`, `.data/test-druh-echo.php`.

**Stav podchycení u nás (implementováno 2026-08-23):** raw JSON ve spisovně
pole drží verbatim a `CaseFileProjectionService` vnořené záznamy
**materializuje jako vlastní řádky `case_file_event`** — kód dědí po
rodičovské události, `event_order` = `poradiUdalosti`, `cancelled` děděné
z rodiče (vlastní příznak vnořený záznam nemá), sloupec `parent_event_order`
odkazuje na agregát **přirozeným klíčem** (poradi rodiče; rodič =
`(case_file_id, source, event_code, parent_event_order)`), takže vazba
přežije i sync mezi prostředími. Identita záznamu (kód, poradi, owner) se
nemění — přesun mezi agregáty i osamostatnění na top-level událost je plain
update, id a URL přežijí. Lazy detail fetch i `bin/hearing-bind.php` tím
vidí termíny automaticky (bind ale potvrzuje až po stažení jejich detailů);
timeline je zobrazuje jako plnohodnotné řádky. **Struktura agregace je pro
uživatele skrytá** — všechny termíny bloku nesou jen jednotný badge
„vícedenní“ (s tooltipem o nespolehlivosti detekce zrušení termínu)
a stránka události vypisuje jednotný seznam termínů bloku se zvýrazněným
aktuálním.

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

Kompletní číselník kódů událostí (28 obecných + 15 NS) máme zachycený —
viz `InfosoudEventType` a
[data/infosoud-ciselniky.json](data/infosoud-ciselniky.json). Pro budoucí
notifikace je klíčový `NAR_JED` (nařízené jednání). Pole `jednani: []`
u události je většinou prázdné — neprázdné agreguje další záznamy
vícetermínového jednání, viz sekce výše.

### Jednání (`POST /api/v1/jednani/vyhledej`)

Modul „InfoJednání“ má vlastní SPA na **infojednani.gov.cz** s vlastním API —
to je kompletně zmapované a implementované (sken síní × dnů → import do
tabulek `hearing*`), viz [infojednani-api.md](infojednani-api.md). Pozorované
validační kódy: `JEDNANI_VALIDATION_0005` „vyplnit jednací síň“, `…_0006`
„vyplnit datum“, `…_0007` „nelze vyhledávat proběhlá jednání“; `JEDNANI_0002`
„pro značku není v následujících 30 dnech jednání“, `JEDNANI_0003`
„nenalezeno“. Původní domněnka z průzkumu 2026-07-19, že jde hledat **jen 30
dnů dopředu**, se nepotvrdila — SPA to tak jen nabízí, API přijme libovolné
budoucí datum (ověřeno i na 2027).

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

### Jak SPA deep-linky zpracovává (zjištěno 2026-07-26)

**Query parametry URL posílá SPA 1:1 jako tělo POST requestu** na API (ověřeno
porovnáním deep-linku s odchozím requestem). Dvě praktické konsekvence:

1. **Deep-link musí nést přesně to, co vyžaduje API.** Máme dvě místa, která staví totéž
   (`InfosoudClient` payload a `InfosoudLinkBuilder` URL) — mohou se rozejít, a přesně to se
   stalo u `udalostId` (viz níže). Při změně jednoho zkontroluj druhé.
2. **Při chybě SPA přesměruje na vyhledávací formulář** (`navigateByUrl(informaceORizeni)`)
   **a nad ním vypíše chybu** (`errorMessage.next(n.message)` → červený banner, drobečková
   navigace „Neznámá chyba“). Text se bere z i18n mapování kódu, takže je obecný a na rozbitý
   odkaz neukazuje: chybějící `udalostId` u CEPR dá „Při načítání události řízení se vyskytla
   neočekávaná chyba. Zopakujte prosím dotaz.“ (`UDALOST_0001`), nesmyslné `typOrganizace`
   dá „Nastala neočekávaná chyba serveru“. Uživatel tedy vidí chybovou hlášku, ale vypadá to
   jako výpadek infoSoudu, ne jako vada odkazu — proto se to snadno přehlédne.

Z toho plyne **levný způsob ověřování našich odkazů**: query parametry převést na JSON a poslat
POST na příslušný endpoint — je to bit po bitu totéž, co udělá SPA, ale dávkově a bez prohlížeče.

### Parametry `detail-udalosti` podle typu události (ověřeno 2026-07-26)

| Typ | Co posílá SPA navíc | Naše chování |
|---|---|---|
| **CEPR (rejstřík `EPR`)** | **`udalostId`** (kompozitní, např. `17614149;217`) | ✅ posíláme (bez něj deep-link nefunguje — CEPR nedohledá událost podle `druhUdalosti`+`poradiUdalosti`) |
| Cizí událost (odvolání) | `cisloSenatuId`, `druhVeciId`, `bcVecId`, `rocnikId` | ✅ shodné 1:1 |
| **Nejvyšší soud** | `cisloSenatuId=0` | ⚠️ neposíláme — **ověřeno, že je redundantní**, odkaz funguje i bez něj |
| CEPR | `organizaceId` **neposílá** | ⚠️ posíláme vždy — ověřeno, že nevadí |

Středník v `udalostId` snese i percent-encoded (`%3B`), takže `http_build_query` stačí.

Ověřeno dávkově na 12 odkazech napříč všemi čtyřmi kategoriemi (CEPR / cizí / ISAS / NS) —
všechny vrátily HTTP 200 se správným `typUdalosti`.

### Další zjištění ze SPA

- **Kolegium NS:** pro `typOrganizace == "ns"` SPA zobrazuje místo stavu
  řízení kolegium odvozené z rejstříku (NS spisy stav nemají). Mapování máme v
  `App\Model\Infosoud\InfosoudCollegium`.
- **`napad` („Druh nápadu“)**: SPA ho vypisuje v hlavičce, když je neprázdný. Zobrazujeme také.
- **`agenda`**: existuje jako pole vyhledávacího DTO a má vlastní validační kód
  (`RIZENI_VALIDATION_0005` „Byla zadaná chybná agenda“), ale **samotné SPA ho při hledání
  neposílá** a my taky ne. *Doplněno 2026-08-27:* ten kód přesto reálně padá — API si
  agendu zjevně **odvozuje z dvojice (rejstřík, soud)** a u `Nc` na KS/VS mu vyjde
  neplatná, i když pole neposíláme (viz „Povolené rejstříky“ výše). Čím se odvození
  řídí, dál nevíme.

## Ročník: dvoumístný u spisů před rokem 2000

Deklarované omezení „jen ročníky > 2006“ (níže) **neplatí doslova** — v infoSoudu žijí
i mnohem starší spisy, typicky opatrovnické (`P`), a ty mají ročník **dvoumístný**.
Ověřeno 2026-07-26 na produkčním API i UI (`0 P 480/61` u OS Děčín, zahájeno 1961-11-08;
UI zobrazuje „**0 P 480 / 61**“, vyhledávací pole `znackaRok` je volný text bez omezení).

**Chování API** (ověřeno na `rizeni/vyhledej` i `udalost/vyhledej`):

| Uložený ročník | Vstup | Výsledek |
|---|---|---|
| dvoumístný (`61`) | `61`, `1961`, **`2061`** | ✅ vždy totéž řízení |
| dvoumístný (`98`) | **`2098`** | ✅ vrátí spis z **1998** |
| dvoumístný (`61`) | `99`, `1899` | ❌ nenalezeno |
| čtyřmístný (`2023`) | `2023` | ✅ |
| čtyřmístný (`2023`) | `23` | ❌ nenalezeno |

Tj. u starých spisů se **matchuje na poslední dvojčíslí**, u moderních přesně.
**Odpověď vždy echuje kanonickou podobu** (`"rocnik": 61`) — a to i uvnitř `znackaId`
(identita použitá u událostí) a v `navazneVeci` (kde se míchá s 4místnými odkazy).

⚠️ **Past:** dotaz na `2098` tiše vrátí spis z 1998. Proto `CaseFileSyncService` po
načtení porovná echované `rocnik` s dotazem a při nesouladu řízení **neuloží**.

### Naše pravidlo

**Interně je ročník vždy čtyřmístný** (1961, 1999, 2024) — v `Spisovka`, ve všech
sloupcích DB i v našich URL (slug je na 4 číslice striktní, dvoumístné URL se odmítají).
Převody dělá `App\Model\Spisovka\CaseYear` a jsou **dva různé směry dovnitř**, které se
nesmí zaměnit:

- `fromUserInput()` — člověk píše zkratku, pivot podle aktuálního roku (`24` → 2024,
  `98` → 1998). Odmítá budoucnost (viz past výše) a ročníky pod rokem 1900.

  **Staré spisy jsou legitimní, ne chyba.** Řízení umí spát desítky let a probudit se
  mimořádným opravným prostředkem — nejstarší nalezený je zatím z 1961, ale to není
  doklad, že starší neexistují. Hranice 1900 je proto **naše mez dotazování**, ne tvrzení
  o neexistenci; ročník starší 10 let jen vyvolá **varování** v `SpisovkaResolver`
  („neobvykle starý – zkontrolujte, zda jste se neuklepli“), které nic neblokuje.
- `fromUpstream()` — data z API; dvojčíslí **vždy** 20. století, **bez pivotu** (infoSoud
  echuje moderní spisy 4místně, takže dvojčíslí nemůže znamenat 20xx).

Ven: `forApi()` stripuje ročník < 2000 na dvojčíslí (posíláme totéž co jejich SPA),
`forDisplay()` renderuje značku, jak ji píše soud („0 P 480/**61**“, nulou doplněné).
Raw JSON sloupce zůstávají **nedotčené** (`rocnik: 61`) — každé čtení z nich musí projít
`fromUpstream()`.

## Omezení dat (uvádí přímo infosoud)

- Deklarováno: jen spisové značky okresních soudů s ročníkem > 2006 a krajských > 2007
  (v praxi ale existují i staré spisy s dvoumístným ročníkem — viz výše).
- Neobsahuje rozhodnutí ani údaje o účastnících — jen procesní události.
- Insolvence → ISIR (isir.justice.cz, má oficiální API), NSS → nssoud.cz.

## TODO / otevřené otázky

- **Ntm proti API zatím empiricky neověřeno** — blocklist mládežnických
  rejstříků je implementovaný (`InfosoudQueryPolicy`, viz sekce *Povolené
  rejstříky* výše) a Ntm blokuje preventivně (SPA whitelist ho neuvádí na
  žádné úrovni); kdyby se někdy ukázalo, že API Ntm obsluhuje, z blocklistu
  ho vyřadit.

- **Chybějící rejstříky Nejvyššího soudu — zatím NEŘEŠÍME.** Číselník kolegií, který
  SPA používá, zmiňuje 10 rejstříků, které v naší tabulce `registry` nejsou:
  `TPJN`, `TS`, `NTN`, `1SKNO`, `2SKNO`, `NCN`, `CPJN`, `NON`, `OPJN`, `OD`.

  Důsledek, kdyby se objevily: validace hlásí **falešnou chybu** („Rejstřík „Tpjn“ neexistuje –
  mysleli jste „TPJ“, „APRN“, „CPJ“?“) a `SpisPresenter::isCourtRegistry()` je pro ně false,
  takže by se u nich nevykreslil ani odkazovatelný čip.

  **Ověřeno 2026-07-26: v datech se nevyskytuje ani jeden z nich** — 0 výskytů napříč
  `case_file` (13 032 řádků), `hearing` (36 346), `case_file_event.ref_registry_norm` (422)
  i `case_file_relation.src/dst_registry_norm` (54). Dokud takový kód reálně nepotkáme,
  necháváme to být; až se objeví, doplnit do číselníku `registry` (level `ns`).

  Pozor při doplňování na **`1SKNO`/`2SKNO`**: upstream bere úvodní číslici jako součást kódu
  rejstříku, kdežto náš `SpisovkaParser` ji přečte jako číslo senátu („1 Skno 1/2024“ →
  senát 1, rejstřík Skno). Než je zavedeme, je potřeba rozhodnout, která interpretace je
  správná — ideálně na reálné značce.
