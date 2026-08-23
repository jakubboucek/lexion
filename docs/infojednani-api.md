# infoJednání — API a číselník jednacích síní

Analýza veřejného API modulu **infoJednání** (https://infojednani.gov.cz/InfoJednani),
z něhož chceme pravidelně skenovat nařízená jednání a vést je ve vlastní DB.
SPA (Angular) volá stejné neautentizované JSON API jako infoSoud
([docs/infosoud-api.md](infosoud-api.md)) — jen jiné endpointy pod `/infosoud/api/v1`
(veřejně vystaveno na `https://infojednani.gov.cz/api/v1`). Backend `2.0.1.9`,
prostředí `MSP_PROD`.

Číselník síní stažený ke dni analýzy: [data/infojednani-jednaci-sine.json](data/infojednani-jednaci-sine.json).

## Endpointy

| Endpoint | Metoda | Popis |
|----------|--------|-------|
| `/api/v1/env` | GET | Verze backendu + prostředí. |
| `/api/v1/organizace/lov` | GET | Číselník **nadřízených** soudů (krajské + vrchní), 10 položek. `{nazev, kod}`. |
| `/api/v1/organizace/podrizene/lov` | GET | Číselník **okresních/obvodních** soudů, 86 položek. `{nazev, kod}`. |
| `/api/v1/organizace/lovkod/jednaci-sin?idOrganizace=<kod>` | GET | Seznam **jednacích síní** daného soudu. Pole objektů `{kod}` — `kod` je rovnou textový popisek síně. |
| `/api/v1/spisova-znacka/druh/lovkod?typ=jednani` | GET | Číselník druhů rejstříku pro jednání (`C`, `NC`, `T`, `P A NC`, `INS`, …). |
| `/api/v1/jednani/vyhledej` | POST | **Vlastní vyhledání jednání** (viz níže). |

Číselník soudů je stejná sada **96 soudů** jako infoSoud (7místné kódy `OSVYCTU`,
`KSVYCHK`, `MSPHAAB`, …) — **nemusíme ho udržovat zvlášť**, mapuje se 1:1 na náš
`court`. Krajské/vrchní vs. okresní je jen rozdělení do dvou dropdownů (nadřízený /
podřízený), ne jiný identifikátor.

## POST `/api/v1/jednani/vyhledej`

Request (JSON, `Content-Type: application/json`):

```json
{
  "okresniSoud": "OSVYCTU",
  "jednaciSin": "č. dveří 307 ve III. podlaží",
  "datumJednani": "2026-07-28",
  "typHledani": "JEDNANI"
}
```

- **`okresniSoud`** — 7místný kód soudu. Pozn.: název pole je matoucí, přijímá i
  kód krajského/vrchního soudu (`KSVYCHK`, `MSPHAAB`). **Povinné.** Bez něj
  `JEDNANI_VALIDATION_0000`. Národní dotaz (bez soudu) API neumožňuje.
- **`jednaciSin`** — popisek síně (přesně dle číselníku). **Povinné**, prázdný string
  ani `null` neprojde (`0005`).
- **`datumJednani`** — `YYYY-MM-DD`. **Povinné** (`0006`). Musí být dnes/budoucnost —
  minulé datum vrací `0007`. Horní hranice **není omezená na 30 dní** (SPA to jen
  tak nabízí); API přijme libovolné budoucí datum a vrátí, co je naplánováno (ověřeno
  i na 2027).
- **`typHledani`** — musí být `"JEDNANI"`. Jiná/chybějící hodnota → `0008`.

Response (200):

```json
{
  "nadrizenaOrganizace": "Krajský soud Hradec Králové",
  "organizace": "Okresní soud Trutnov",
  "jednaciSin": "%",
  "datum": "28.07.2026",
  "typ": "JEDNANI",
  "udalosti": [
    {
      "cislo": 11, "bcVec": 95, "druh": "C", "rocnik": 2026,
      "datum": null, "cas": "08:30",
      "predmetJednani": null, "resitel": "Mgr. Klečková Kutišová Kateřina",
      "jednaniZruseno": null, "neverejneJednani": null,
      "druhJednani": "Jednání", "vysledek": null,
      "datumZapisuVysledku": null, "jednaciSin": null
    }
  ],
  "platneK": "2026-07-25T07:39:00+02:00"
}
```

Spisová značka jednání = **pětice** `(organizace, druh, cislo=senát, bcVec=běžné číslo, rocnik)`
— tj. `senát druh bcVec/rocnik`, např. `11 C 95/2026`. To odpovídá naší identitě spisu
(soud, rejstřík, senát, číslo, ročník) a mapuje se na `case_file`. `druhJednani`
(„Jednání“ / „Jiný soudní rok“), `cas`, `resitel` (soudce), `jednaniZruseno` = "Ano"/null,
`neverejneJednani`. **`jednaciSin` v události je vždy `null`** — viz kritické zjištění níže.

### Zvolená strategie: per-síň sken (přesná shoda)

Síň jednání je pro nás **důležitý údaj** (zpětné dohledání „byl jsem u jednání v síni X“),
proto skenujeme **per-síň** — na každou síň z číselníku posíláme dotaz s jejím **přesným
názvem**. Přesně tak, jak to dělá veřejné SPA (jeden request = jedna síň + jeden den).
Důsledky:

- Každý request vypadá **identicky jako reálný uživatelský dotaz** → nevyčnívá v logu.
- **Síň známe z parametru dotazu** — to je jediná cesta, jak ji získat (v odpovědi je
  `jednaciSin` u události **vždy `null`**, i u přesného dotazu).
- Cena: **1361 requestů/den**, tj. ~40 830 za 30denní okno (viz níže).

Server neomezuje rate (žádný token/captcha), přesto skenujeme šetrně
(10 s mezi requesty; [paměť: šetrnost k cizím serverům](../CLAUDE.md)).

Sken provádí CLI tool **`bin/infojednani-scan.php`** (standalone, bez Nette; volby `--out`,
`--days`, `--from`, `--delay`, `--log-dir`; ukládá každou 200 odpověď doslovně jako
`<out>/<soud>/<den>/<index_síně>.json`, resumovatelný přes existenci souboru; číselník síní
si na začátku stáhne a zacachuje do `<out>/_codelist.json`). Výstup je gitignorovaný
(`/.data/`). Buňky pro minulé dny scanner **vůbec neodesílá** — porovná datum proti dnešku
(Europe/Prague) a přeskočí je se záznamem `skip_past` v logu (API by stejně vrátilo
HTTP 400 / `0007`). Každý pokus se zapisuje do **append-only JSONL logu** (viz *Log skenu*
níže).

> **Provozní past (ověřeno v terénu):** `docker compose exec … php bin/infojednani-scan.php`
> po **Ctrl-C na klientovi neukončí php proces uvnitř kontejneru** — jen se od něj odpojí.
> Každý „restart“ (nový odstup ap.) tak spustí **dalšího workera vedle běžícího**; víc
> procesů pak paralelně skenuje stejný seznam a závodí o stejné soubory. Data se
> nepoškodí (resume přes existenci souboru je zdedupuje — buňku stáhne první, ostatní ji
> `SKIP`nou), ale projeví se to jako **`SKIP (už staženo)` na prvním průchodu**,
> rozházené SKIPy, duplicitní čísla requestů v jednom stdout (prolnuté čítače) a vyšší
> zátěž na justiční server, než je zamýšleno. Řešení: před novým během **zabít starý
> proces zevnitř kontejneru** (`docker compose exec web sh -c 'ps ax | grep infojednani'`
> → `kill <pid>`), nebo spouštět dlouhé tooly přes `-d` a vědomě je ukončovat.

### Neúmyslné chování wildcard `%` — NEPOUŽÍVÁME

Pole `jednaciSin` se na serveru vyhodnocuje jako **SQL `LIKE`** (ověřeno: `%` vrátí všechna
jednání celého soudu, `_` vrátí 0, protože žádný popisek není jednoznakový). Teoreticky by
to umožnilo skenovat jen **per-soud** (96 requestů/den místo 1361).

**Vědomě to nepoužíváme**, protože:

1. Je to fakticky využití chyby (neescapovaný `LIKE`) — použití mimo zamýšlený režim aplikace.
2. Je **opravitelné** (escapování vstupu / přechod na přesnou shodu) → scanner by ze dne na
   den přestal fungovat.
3. **Vyčnívá** — dotaz `jednaciSin = "%"` je v jejich logu anomálie, kterou běžný uživatel
   nevygeneruje; nechceme na aplikaci přitáhnout nežádoucí pozornost.

Navíc by wildcard stejně **neuchoval síň** (viz výše), což je pro nás klíčové.

## Náročnost plného skenu

| Strategie | Zachytí síň? | Requestů/den | 30denní rolling okno |
|-----------|:---:|---:|---:|
| **Per-síň exact (1361 síní)** — používáme | **ano** | **1361** | **~40 830** |
| Per-soud wildcard (`%`) — nepoužíváme | ne | 96 | ~2 880 |

Při 10 s odstupu je plný 30denní sken ~113 h čistého času — proto je scanner resumovatelný
a počítá se s během na etapy.

## Validační kódy

| Kód | Význam |
|-----|--------|
| `JEDNANI_VALIDATION_0000` | chybí/špatný soud (`okresniSoud`) |
| `JEDNANI_VALIDATION_0005` | chybí `jednaciSin` (prázdný/`null`) |
| `JEDNANI_VALIDATION_0006` | chybí `datumJednani` |
| `JEDNANI_VALIDATION_0007` | `datumJednani` v minulosti |
| `JEDNANI_VALIDATION_0008` | špatný/chybějící `typHledani` (musí být `JEDNANI`) |

## Číselník jednacích síní

Staženo přes `organizace/lovkod/jednaci-sin` pro všech 96 soudů:
**1361 síní** (min 3 = Vrchní soud Olomouc, max 57 = Městský soud Praha, průměr 14).

**Síň nemá identifikátor** — API vrací jen `{kod}`, kde `kod` je volný český popisek
(„č. dveří 307 ve III. podlaží“, „108PCE - přízemí“, „A001 přízemí, vchod z Karlova nám.“).
Formáty jsou napříč soudy nekonzistentní (číslo dveří, patro, budova, adresa, justiční
areál; délka až 113 znaků). Kromě fyzických síní obsahuje i „místa konání“:
`na místě samém`, `MÍSTNÍ OHLEDÁNÍ`, `výslech mimo budovu soudu`, `Věznice …`,
`Psychiatrická nemocnice …`, `Kancelář soudce`, `videokonferenční místnost`. V rámci
jednoho soudu jsou popisky unikátní (žádné duplicity), ale **napříč soudy nejsou** klíčem
(stejné „na místě samém“ u 13 soudů). Popisky navíc obsahují překlepy/varianty velikosti
(`MÍSTO SAMÉ` × `Na místě samém` × `na místě samém`) → jako identifikátor síně nespolehlivé.

Síň evidujeme jako **volný text vázaný na soud** (přesný popisek z číselníku), plněný
z per-síň skenu. Textový popisek je jediný identifikátor, který infoJednání nabízí — je to
dlouhodobý a relativně konzistentní stav aplikace, se kterým počítáme.

### Popisek síně je společný klíč obou zdrojů (ověřeno 2026-07-26)

Původní obava, že popisek je „infoJednání-specifický“ (a číselník by tedy byl jen lokální
pomůcka), se **empiricky vyvrátila**. Na vzorku 10 řízení s off-site jednáními (věznice,
psychiatrická nemocnice, „místo samé“, videokonference, kancelář soudce) stažených z infoSoudu
přes `bin/infosoud-fetch-hearings.php`:

- **`JED_SIN` v infoSoudu = tentýž string** jako položka číselníku infoJednání i jako parametr
  jeho dotazu: **21/21** distinct párů `(soud, JED_SIN)` přesně odpovídalo číselníku
  (žádná normalizace, shoda znak po znaku).
- **Křížové párování jednání funguje: 12/12** jednání v okně skenu se spárovalo na
  `(soud, sp.zn., datum, čas)` a **u všech se popisek síně shodoval**. To je přímé potvrzení,
  že mechanismus `court_binding` → `confirmed` (shoda času a místa napříč zdroji) je funkční.
- **Off-site jednání nemají v infoSoudu žádná upřesňující metadata.** Detail události nese jen
  6 atributů, všechny `JED_*` (`JED_SIN`, `JED_D_ZAC`, `JED_ZRUS`, `JED_DRUH`, `JED_D_Z_V`,
  `JED_VYSLED`); `napad` a `navazneVeci` jsou prázdné, žádné pole s adresou/poznámkou neexistuje.
  Veškerá specifičnost místa je jen v tom, co soud sám napsal do popisku (např. bohnický popisek
  obsahuje celou adresu a číslo oddělení).

Proto je `(court_kod, label)` **nejsilnější klíč, jaký kdy dostaneme**, a zároveň **join mezi
zdroji** — číselník je tedy plnohodnotný a patří do DB (tabulka `hearing_room`).

Vedlejší zjištění k odhadu soudu: všech 10 vzorků (včetně věznic a nemocnice) infoSoud našel
**pod soudem síně** → u nich soud síně == domovský soud. Nepokrytý zůstává scénář **dožádání**
(soud A požádá soud B o výslech), kde by se jednání mohlo objevit v síni soudu B se spisovkou
soudu A; na vzorku se nevyskytl, ale nelze ho vylučovat.

### Vazba na síň je čistě textová, číselník se hýbe (ověřeno 2026-08-23)

Číselník síní soudy průběžně ručně editují: mezi snapshoty 17. 8. a 23. 8. se
z ~1 400 položek 27 přidalo a 43 zmizelo (~5 % za týden) — mix nových síní,
promazaných pseudo-síní (věznice, „místní šetření", kancelář soudce) a editací
labelu (fuzzy páry typu `č.d. 426 IV. podlaží` → `č.d. 421`, OS Jihlava).
**Přejmenování se do infoSoudu nepropisuje zpětně**: ruční lustrace kandidátů
(7 P a Nc 163/2026 OS Jihlava, 45 T 9/2022 KS Ostrava aj.) potvrdila, že
`JED_SIN` u proběhlých jednání drží původní text — vazba je textový snapshot,
nikoli relace. Důsledek: historické řádky `hearing_room` se nikdy nemažou
a stejná fyzická síň může v čase existovat pod více labely.

**Přesuny jednání mezi síněmi existují a `hearing_observation` je jediná
paměť, která je drží** (klíč obsahuje `observed_at` i síň, primární síň
jednání se záměrně nepřepisuje). K 2026-08-23 evidujeme 60 jednání
s pozorováními ve víc síních; po zohlednění `jednaniZruseno` v raw JSON
pozorování se rozpadají na:

- **32× zrušeno + nařízeno jinde se stopou** — infoJednání nechává zrušený
  zápis viset v buňce staré síně (`jednaniZruseno=Ano`, „odročeno"), vedle
  běží nové nařízení na týž den a čas → v `hearing` se slijí do jednoho
  řádku (např. `1 C 135/2026` OS Strakonice, 6a→5). Stopa je i v infoSoudu
  (pár ZRUS_JED + NAR_JED).
- **14× skutečný souběžný dvojzápis** — obě síně živé v témže snapshotu,
  nápadně často dvojice spojených opatrovnických věcí (P + P a Nc).
- **13× TICHÝ PŘESUN, ověřeno 2026-08-23 i na straně infoSoudu**: starý
  zápis zmizel z pozdějšího snapshotu bez příznaku zrušení a detail
  infoSoudu má **jediné NAR_JED, jehož `JED_SIN` už nese novou síň, bez
  jakéhokoli ZRUS_JED v timeline** (`10 T 3/2026` MS Praha 114→301,
  `16 C 14/2026` OS Domažlice 28→30, `6 TO 216/2026` KS Ostrava 248B→11A).
  `JED_*` atributy se tedy generují z živého záznamu jednání v ISAS
  a přepisují se zpětně beze stopy — jediný záznam o původní síni drží
  naše `hearing_observation`. Pozor: náš lazy refresh detailu události
  takovou změnu tiše přepíše i u nás (integritní guard hlídá jen typ
  a datum), historie zůstává pouze v observacích.

### Hypotéza: „číselník" síní je agregát nařízených jednání (analýza 2026-08-23)

Endpoint `organizace/lovkod/jednaci-sin` se možná nechová jako spravovaný
číselník, ale jako **agregovaný SELECT DISTINCT síní z aktuálně nařízených
(současných a budoucích) jednání**. Presence analýza tří snapshotů
(25. 7. / 17. 8. / 23. 8.; vzor = přítomnost v každém z nich):

| vzor | síní | interpretace |
|---|---|---|
| 111 | 1 287 | stabilní jádro |
| 100 / 110 | 45 / 25 | zmizely (po 1., resp. 2. snapshotu) |
| 011 / 001 | 93 / 27 | přibyly (od 2., resp. 3. snapshotu) |
| **101** | **4** | **zmizely a znovu se objevily** |
| **010** | **18** | **existovaly jen v prostředním snapshotu** |

Podpora hypotézy (zatím nevyvrácena, nepotvrzeně):

- **25/25 síní zmizelých k 23. 8. nemá jediné živé jednání ≥ 23. 8.**
  (ověřeno proti datům ze skenu 17. 8., jehož okno sahá za 23. 8.) — nula
  rozporů s agregátem.
- Vzor 010 jsou hlavně **pseudo-síně vázané na událost** („Věznice Světlá
  n/S.", „místní šetření", kanceláře) — objeví se s nařízeným úkonem
  a zmizí s ním.
- Síně blikají (vzor 101, např. `OSZPCPM trakt B kancelář č. 20`,
  `OSSEMOS č. dv. 603`), což správě ručního číselníku neodpovídá.
- Nuance: 16 z 18 síní vzoru 010 nemělo ve 30denním okně skenu **žádné**
  jednání → pokud agregát, pak přes **všechna budoucí** jednání (horizont
  nařizování sahá měsíce dopředu, viz 10 T 3/2026 s termíny do listopadu),
  ne jen přes okno ~30 dní.
- **Ověřitelná predikce** (zapsáno 23. 8.): znovuobjevené síně 101 mají
  známá jednání jen ve starém okně — pokud hypotéza platí, běžící sken
  v nich najde nová budoucí jednání. Totéž pro 27 nových síní.

**Provozní důsledky (platí bez ohledu na potvrzení hypotézy):**

1. **Každý sken musí začínat čerstvým stažením číselníku** — starý
   `_codelist.json` se do nového skenu nikdy nepřenáší (scanner to tak
   dělá: nový `--out` adresář = nový fetch). Jinak se přeskočí síně,
   které se mezitím objevily, a jednání v nich jsou neviditelná
   (síň je parametr dotazu; co nevyjmenujeme, to nedostaneme).
2. **Snapshoty `_codelist.json` nikdy nemazat** — jsou jediným zdrojem
   historie presence; `hearing_room.first_seen/last_seen` mezery
   nezachytí (znovuobjevená síň vypadá jako nepřetržitě přítomná).
3. **TODO:** evidovat presenci síně per snapshot (interval/observační
   tabulka k `hearing_room`), aby blikání šlo analyzovat z DB a ne
   ručním diffem JSON souborů.

## Tvary nasbíraných dat (první plný sken 2026-07-25 … 08-24)

Analýza 41 745 response souborů (31 dní, kompletní kromě 25. 7., viz níže). Envelope má
11 polí; top-level `cislo`/`bcVec`/`druh`/`rocnik` jsou echo requestu (u nás null),
`datum` = dotazovaný den, `jednaciSin` = echo síně, `organizace`/`nadrizenaOrganizace` =
názvy soudů, `platneK` = čas platnosti, `udalosti` = pole událostí.

Událost (`udalosti[]`) má **14 polí, vždy přítomných**. V našich (budoucích) datech jsou
**vždy `null`**: `datum`, `predmetJednani`, `datumZapisuVysledku`, `jednaciSin` (předmět a
síň se z odpovědi nedozvíme — síň jen z parametru dotazu, předmět jen z infoSoudu).

Vyplněná pole:
- `cislo` = **senát** (může být `0`; ~9 % událostí), `bcVec` = běžné číslo, `rocnik` = ročník.
- **`rocnik` je v odpovědi 2- i 4-místný** (rozsah 61…2026) — staré (typicky opatrovnické `P`)
  spisy mají dvojmístný rok (`61`, `84`, `99`), stejně jako v infoSoudu. **Interně ho ukládáme
  vždy čtyřmístně** (`1961`), převod dělá `CaseYear::fromUpstream()` při importu — jinak by
  `hearing.year` nešlo joinovat s `case_file.year`. Viz [infosoud-api.md](infosoud-api.md).
- `druh` = rejstřík vč. složeného **„P A NC“** (s mezerami; nejčastější hned po `C`).
- `cas` = vždy `HH:MM` (u neveřejných zasedání bývají synteticky `00:00`/`00:30`/… — čas nejspíš neveřejný).
- `resitel` = soudce („Titul Příjmení Jméno“), vyplněno u všech kromě 1.
- `druhJednani` = **typ jednání** — 14 hodnot: `Jednání` (25 901), `Vyhlášení rozsudku`,
  `Veřejné zasedání`, `Hlavní líčení s dokazováním`, `Hlavní líčení`, `Jiný soudní rok`,
  `Výslech`, `Přípravné jednání`, `Přezkumné jednání`, … (2 313× `null`).
  **Pozor:** infoJednání typ jednání **má** (vč. „Hlavní líčení“) — dřívější předpoklad, že
  ho nese jen infoSoud, neplatí. Zdroje mají vlastní slovníky, které bude třeba sladit.

Stavová pole (odpověď na „zmizí zrušená jednání?“): **nezmizí, zůstávají evidovaná**.
Z 36 458 událostí:
- `jednaniZruseno = "Ano"`: **3 405** (zrušená/odvolaná/odročená).
- `neverejneJednani = "Ano"`: **126**.
- `vysledek` vyplněn: **2 662** — převažuje předběžný výsledek („Jednání bylo odročeno …“
  1 238, „Jednání bylo odvoláno“/„ODVOLÁNO“ 1 305), ale jsou i skutečné výsledky
  („Vyhlášen rozsudek“, „Při jednání došlo k uzavření smíru“, „USNESENÍ“, „žaloba vzata zpět“,
  „Výslech proveden …“). Křížově: zruseno=Ano bez výsledku 824, zruseno=Ano s výsledkem
  2 581, výsledek bez zruseno 81.

Identita a duplicity: **34 680 distinct řízení** (soud, druh, senát, číslo, rok),
**36 346 distinct jednání** (+ datum, čas). **38 jednání figuruje ve 2 síních téhož soudu**
→ síň je atribut 0..N, ne součást klíče jednání.

**Příslušnost soudu (klíčové omezení):** z infoJednání známe jen **soud síně** (= místo
konání), ne domovský soud spisu. 45 kolizí „stejná sp.zn. + datum + čas pod 2 soudy“ jsou
**náhodné** (potvrzuje, že sp.zn. je unikátní jen se soudem — viz identita spisu v CLAUDE.md).
U jednání mimo budovu (dožádání, věznice, „na místě samém“) může být soud síně ≠ domovský
soud spisu. **Domovský soud proto z infoJednání nelze určit s jistotou** — řešit křížově
s infoSoudem; nikdy nepovyšovat soud síně na `case_file.court` bez potvrzení.

## Datový model (hearing)

Migrace `migrations/structures/2026-07-26-00-create-hearing-tables.sql` (podrobný popis
v komentářích migrace):

- **`hearing`** — sloučená projekce, jeden řádek = jedno jednání. Klíč
  `(venue_court_kod, registry_norm, senate, bc_number, year, hearing_date, hearing_time)`
  (síň **není** v klíči). `venue_court_kod` = soud síně = **kandidát** domovského soudu;
  `case_file_id` (nullable, `ON DELETE SET NULL`) a `court_binding` (`venue_guess`/`confirmed`)
  drží sílu vazby. Identita spisu je denormalizovaná (matchujeme i bez `case_file` řádku).
  `year` je **vždy čtyřmístný** (raw dvojčíslí z API převádí importér přes
  `CaseYear::fromUpstream()`); `hearing_observation.raw_json` si dvojčíslí ponechává.
  `idx_hearing_spisovka` slouží předvýběru soudu na HP z pouhé spisovky.
- **`hearing_observation`** — raw pozorování per zdroj (`infojednani`/`infosoud`), `observed_at`
  (z `platneK`), `room`, `raw_json`; unikát `(hearing_id, source, observed_at, room_key)` =
  idempotentní import a zároveň dvě síně u téhož jednání jako dvě pozorování (`room_key` je
  generovaný NOT NULL zástupce nullable `room` — u infoJednání je síň vždy vyplněná, je to
  parametr dotazu, ale budoucí zdroj `infosoud` ji mít nemusí a NULL by unikát obešel; migrace
  `2026-07-27-00`). Umožňuje re-projekci `hearing`.
- **`hearing_room`** (migrace `2026-07-26-01-create-hearing-room-table.sql`) — číselník síní,
  klíč `(court_kod, label)`, + `kind`/`off_site` klasifikace (viz níže), `first_seen`/`last_seen`/
  `retired_at` pro životní cyklus. `hearing.room_id` je nullable FK (`ON DELETE SET NULL`) —
  `hearing.room` drží popisek verbatim, takže jednání na existenci číselníkového řádku nezávisí.

Log výpadků skenu (JSONL v `web/log/infojednani-scan/<YYYY-MM-DD>.jsonl`, dělený po dni, viz
níže) je zatím mimo DB; tabulku `scan_log` lze doplnit s importérem, pokud budeme chtít výpadky
dotazovat společně s daty.

### Import skenu do DB

`bin/infojednani-import.php` (volby `--dir`, `--dry-run`) nahraje hotový sken do tabulek:
nejdřív číselník síní z `_codelist.json` do `hearing_room` (vč. klasifikace `kind`/`off_site`
z popisku), pak projde všechny odpovědi a vytvoří `hearing` + `hearing_observation`.

**Import je idempotentní a při opakování nic nezapisuje** — jednání jsou klíčovaná
`(soud síně, identita spisu, datum, čas)`, pozorování `(hearing, source, observed_at, room)`,
a atributy se přepisují jen z **striktně novějšího** pozorování. Ověřeno opakovaným během
(0 nových, 0 změněných, 0 pozorování).

Importér **záměrně neplní `case_file_id` ani nepovyšuje `court_binding`** — z infoJednání
známe jen soud síně, takže vše zůstává `venue_guess` (viz PRIORITA v TODO).

Výsledek prvního importu (sken 2026-07-25 … 08-24, dev DB):

| tabulka / metrika | počet |
|---|---:|
| `hearing_room` | 1 361 (courtroom 1 289, onsite 32, office 17, prison 9, external 8, hospital 5, video 1) |
| `hearing` | 36 346 (z 36 458 událostí — 112 duplicit sloučeno) |
| `hearing_observation` | 36 384 (74 událostí je v odpovědi uvedeno dvakrát → unikát je sloučil) |
| z toho zrušených / neveřejných / s výsledkem | 3 347 / 126 / 2 630 |
| jednání v off-site místě | 430 |

Ověření proti infoSoudu: všech **12 jednání** stažených přes `bin/infosoud-fetch-hearings.php`,
která spadají do okna skenu, se v tabulce `hearing` našlo na `(soud, sp.zn., datum, čas)`
a **u všech seděl i popisek síně**.

### Doplňování `hearing` z infoSoudu (analýza 2026-07-26, k realizaci)

Motivace: `hearing` pokrývá jen okno skenu (od 2026-07-25, +30 dní) — chybí minulost i
vzdálenější budoucnost. infoSoud oboje má, tak ať se do `hearing` propíše cokoli, co cestou
získáme.

**Výchozí zjištění: ta data už v DB jsou.** V `case_file_event` je dnes **134** událostí
`NAR_JED`/`ZRUS_JED` s rozsahem **2023-01-16 … 2026-08-27**, tedy hluboko mimo okno skenu.
Nejde tedy o sběr, ale jen o **projekci**. A dělí se na dva zásadně různé druhy:

| druh | počet | co víme | ambiguita |
|---|---:|---|---|
| **s detailem** (`detail_json`) | 43 | datum + **čas** (`JED_D_ZAC`) + **síň** (`JED_SIN`) + soud | **žádná** |
| **tenké** (detail nenačten) | 91 | jen datum + soud | vysoká |

**Doporučení: dělit podle úplnosti záznamu, ne podle zdroje.**

1. **Úplné záznamy** (z infoJednání i z detailu `NAR_JED`) jdou do `hearing` a slévají se
   **deterministicky přes stávající unikátní klíč** — žádná heuristika, žádná deduplikace.
   Bonus: `venue_court_kod` jde pro infoSoud odvodit z `JED_SIN` přes číselník `hearing_room`
   (ověřeno na 43 detailech: 42× síň vlastního soudu, 1× popisek sdílený víc soudy, 0× cizí soud).
2. **Tenké záznamy zůstávají v `case_file_event`**, kde už jsou a kde je pro ně zavedený
   dvoufázový vzor thin/full (`detail_fetched_at`). Nic se nezahazuje, jen se to neduplikuje.
3. **Job dotahuje detaily** tenkých `NAR_JED` (1 request na událost, dnes by šlo o 91) → záznam
   se stane úplným → propadne do `hearing` bez jakéhokoli párování.

Tím se pořadí obrací: **nejdřív doplnit rozlišovací údaj, teprve pak vložit** — místo „vložit
teď, rozlišit potom“.

**Slabá místa varianty „ukládat semi-duplicity a deduplikovat později“** (proto se nedoporučuje):

- **NULL v čase rozbije unikátní klíč.** MariaDB bere NULLy jako různé — ověřeno, 3 identické
  tenké řádky prošly. Bylo by nutné zavést generovaný `time_key` se sentinelem `unknown`;
  **`00:00` jako sentinel použít nelze**, je to reálná hodnota (36 řádků, neveřejná zasedání).
- **Část případů je nerozlišitelná i při dokonalé znalosti soudu:** 277 skupin (0,77 %) má
  **totéž řízení, tentýž soud a den, ale 2–3 jednání** — tenký záznam k nim nelze přiřadit.
- **Kolizní plocha párování jen podle data:** 604 dvojic (spisovka, datum) se koná u 2+ soudů
  (1,7 %). Omezení na `venue == domovský soud` většinu zabije, ale ne případ dožádání
  (cizí řízení v naší síni + vlastní řízení téže spisovky + tentýž den).
- **Slučování řádků znamená přepis referencí.** Dnes levné (jen `hearing_observation`), ale
  jakmile se `hearing.id` dostane do URL nebo uživatelských dat (sledování, notifikace), mazání
  id rozbije odkazy → musely by se zavést náhrobky `merged_into_id`.
- **Semi-duplicity by mezitím viděl uživatel** (totéž jednání dvakrát ve výpisu) → UI by muselo
  slučovat zobrazovací heuristikou, tedy přesně tím, čemu jsme se chtěli vyhnout.
- **Neušetří to requesty.** Rozlišovací údaj (detail události) stojí stejný request tak či tak;
  mění se jen okamžik, kdy se zaplatí.

Kdy by varianta se semi-duplicitami přesto dávala smysl: kdybychom čekali, že detaily **nikdy**
nedotáhneme (moc sledovaných řízení) a chtěli mít vše dotazovatelné v jedné tabulce. I to se ale
řeší bez duplicit — pohledem (`VIEW`)/UNIONem nad `hearing` + `case_file_event`.

### Kandidáti soudu pro formulář na HP (✅ implementováno)

Nezávislá věc od předchozího: předvýběr soudu na HP hledá kromě `case_file` i v
`hearing.venue_court_kod` (`HearingRepository::countPerVenueBySpisovka()`, index
`idx_hearing_spisovka`) — na HP i v živé validaci (`Spisovka:validate`). Pokrytí: `hearing`
má **28 249** distinct spisovek proti 13 018 v `case_file`, z toho **23 861 (84 %) se koná
u jediného soudu** → čistý předvýběr; zbytek jen vypíše kandidáty (stejné pravidlo jako
u cache: nikdy nepřepsat ruční volbu, nabídku neomezovat). Implementace navíc přidala
pravidlo **„jednání se uplatní, jen když cache mlčí“** — cache je blíž domovskému soudu,
soud síně jen napovídá.

Formulace v UI říká „**evidujeme jednání** s touto značkou u …“, ne „spis je veden u …“ —
soud síně není totéž co domovský soud. Pozn.: tenké záznamy z infoSoudu by pro tenhle účel
**nepřinesly nic nového** — pocházejí z řízení, která už v `case_file` jsou, a to HP
prohledává.

### Párování `hearing` ↔ `case_file`

`bin/hearing-bind.php` (volba `--dry-run`), idempotentní, dvě fáze:

1. **GUESS** — jednání se naváže na řízení v cache se **stejnou identitou u soudu síně**
   (unikátní klíč `case_file` zaručuje nejvýš jednu shodu). `court_binding` zůstává
   `venue_guess` — vazba je domněnka, ne fakt. **Nikdy se nepáruje napříč soudy naslepo**:
   identita bez soudu není unikátní (ověřeno — v datech je 4 388 sp. zn. sdílených více soudy).
2. **CONFIRM** — korroborace proti infoSoudu, který je o domovském soudu autoritativní (řízení
   se stahovalo od konkrétního soudu). Z cache `case_file_event.detail_json` se vezme
   `JED_D_ZAC` + `JED_SIN`; při shodě identity, data, času **a síně** se nastaví `case_file_id`
   a `court_binding = 'confirmed'`.

Fáze 2 **záměrně páruje i napříč soudy** — právě to je případ, který jinak nelze odvodit
(jednání v síni cizího soudu: dožádání, věznice). Bezpečné to dělá **shoda popisku síně**:
kolize identity napříč soudy jsou běžné, ale kolize sdílející identitu, datum, minutu *a*
popisek síně reálně nehrozí. Když jedna strana síň nemá, padá se na identitu + datum + čas.
Neshoda síně = jednání se **nepotvrdí** a vypíše se jako anomálie k prozkoumání. Když
infoSoud ukáže na **jiné řízení**, než na které fázi 1 navázal odhad, fáze 2 vazbu
**převáže** („infoSoud wins“ — statistika `relinked`).

Stav po prvním běhu (dev DB): **12 `confirmed`** (všechna s `case_file_id`), **45** dalších
navázaných jako `venue_guess`, zbytek (36 334) zatím bez vazby — cache řízení je dnes hlavně
ISIR, takže protějšek existuje jen u zlomku jednání. Pokrytí `confirmed` poroste s tím, jak se
budou stahovat data z infoSoudu pro sledovaná řízení.

Ověřeno na reálné kolizi: `4 PP 47/2026` existuje u OSJICCB (jednání 18. 8.) i u OSSCEMO
(jednání 12. 8., v cache). Jednání u OSJICCB zůstalo **nespárované**, jednání u OSSCEMO je
`confirmed` — tj. kolize identity nevede k chybné vazbě.

## TODO / otevřené otázky

- **PRIORITA: co nejpevnější vazba `hearing` ↔ `case_file` (příslušný soud).**
  **Mechanismus hotový** — `bin/hearing-bind.php` (viz *Párování* výše): odhad podle soudu síně
  (`venue_guess`) + potvrzení křížem s infoSoudem přes shodu identity/data/času/síně
  (`confirmed`). **Zbývá:**
  - **Pokrytí** — dnes je `confirmed` jen 12 jednání, protože z infoSoudu máme detaily jednání
    jen u hrstky řízení. Poroste automaticky se sledovanými řízeními; zvážit dávkové
    dostahování `bin/infosoud-fetch-hearings.php` pro řízení, u kterých `hearing` existuje.
  - **Klasifikace off-site do síly odhadu** — `hearing_room.off_site` se zatím do `court_binding`
    nepromítá (u off-site síní je odhad soudu principiálně slabší než u běžné soudní síně).
  - ~~Kandidáti pro předvýběr soudu na HP~~ — ✅ hotovo, viz sekce *Kandidáti soudu pro
    formulář na HP* výše.
  - **Negativní výsledek ověření** („spis u soudu síně není“) nemá kam uložit — je stejně drahý
    jako pozitivní a bez uložení by se dotaz opakoval při každé návštěvě. Řešení: doplnit
    `court_binding` o **`refuted`** (+ čas ověření). Kandidát je u jednání vždy jen jeden
    (soud síně), seznam kandidátů evidovat netřeba. **U jednání je vyloučení trvalé** (pro
    existující jednání musí spis existovat), zatímco u hledání soudu podle SZ je 404 pomíjivé
    (soudy plní číselné řady různě rychle, řízení může vzniknout později) — proto se vyloučení
    drží u jednání a **žádná sdílená dlouhodobá cache negativ nevzniká**. Detaily a UX viz
    [roadmap.md](roadmap.md), sekce *Jednání: UX nejisté vazby na spis*.

  Původní zadání a kontext:
  - **Odhadnout pravděpodobný soud podle místa konání** (soud síně). Očekávání: v naprosté
    většině soud síně == domovský soud; **výjimky** = jednání mimo budovu (věznice,
    psychiatrická nemocnice, „na místě samém", dožádání/výslech mimo soud), kde to určit nelze
    — tyto síně umět rozpoznat a označit vazbu jako nejistou.
  - **Zpevnit na jisto až křížovou shodou s infoSoudem** — když se z infoSoudu načte **stejné
    jednání na stejném čase a místě**, vazba (a domovský soud) se potvrdí.
  - **Stav vazby** evidovat na záznamu (např. `venue_guess` / `confirmed`), ať je jasné, co je
    odhad a co ověřeno.
  - **Využití:** kandidáti podle místa konání slouží jako **předvýběr soudu na HP** při zadání
    spisovky (obdoba stávajícího předvýběru z cache `case_file` — viz *Komponenta spisovky*
    v CLAUDE.md), a obecně ke zpřesnění dohledání spisu.

- **Trvalý log výpadků skenu — ✅ implementováno.** Scanner zapisuje **každý pokus**
  do append-only JSONL (`web/log/infojednani-scan/<YYYY-MM-DD>.jsonl`, dělený po dni,
  volba `--log-dir`): timestamp, soud, datum, síň, status `ok`/`fail`/`skip_past`
  (u chyby HTTP status, hláška a číslo pokusu) + záznamy `run_start`/`run_end`.
  Motivace zůstává platná: díry v datech musí být zpětně analyzovatelné, protože
  **buňka pro den, který zestárne do minulosti, už nikdy nepůjde doplnit** (API vrací
  pro minulé datum HTTP 400 / `0007`; přesně to potkalo 25. 7. — 446 buněk chybí
  trvale — a vedlo k požadavku). **Zbývá:** vyhodnotit z logu vzorec výpadků (uživatel
  pozoruje víc výpadků v noci → noční sken možná nebude ideální) a případně log
  importovat do DB (`scan_log`), pokud budeme chtít výpadky dotazovat společně s daty.
- **Životní cyklus síní** — schéma na něj má sloupce (`first_seen`/`last_seen`/`retired_at`),
  ale **logika zatím neexistuje**. Sken pořád jede podle číselníku staženého na začátku běhu
  (`_codelist.json`) a bere popisek jako stabilní. Zbývá dořešit: jak při importu číselníku
  označit zmizelé síně jako `retired`, jak (a zda vůbec) párovat „stejnou“ síň po přejmenování
  (popisek je klíč, takže přejmenování = nová síň) a jak reportovat změny mezi snapshoty.
- **Klasifikace `kind`/`off_site` je jen heuristika** (regex nad popiskem, funkce `classifyRoom`
  v importéru) a **zatím se ručně nedočišťovala**. Hraniční případy k prověření: „Vazební
  místnost“ (je v budově soudu → záměrně *není* off-site, matchuje se jen „věznice“),
  „Kancelář soudce“ a videokonferenční místnost (v budově → off-site = 0). Sloupec `note`
  je připravený pro ruční poznámky ke kurátorství.
