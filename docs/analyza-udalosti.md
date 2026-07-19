# Analýza: detail události, robustnost odkazů, rozpad JSON do tabulek

> **Stav: ✅ implementováno 2026-07-19** (migrace 2026-07-19-03/04 + datová
> migrace `migrations/data/2026-07-19-00-project-proceeding-events-relations.php`,
> `ProceedingProjectionService`, stránka `/spis/<soud>/<znacka>/udalost/<id>`).
> Dokument zůstává jako zdůvodnění návrhu.

Analýza proveditelnosti z 2026-07-19 (bez implementace). Podklady: 8 detailů
událostí různých typů staženo z API (spis 2 T 101/2024 OS Praha 1 + ODVOLANI
z 5 To 320/2025 MS Praha), rozbor SPA komponent (`DetailUdalostiComponent`,
`DetailRizeniComponent`, resolvery, i18n) a nápověda SPA (`/napoveda` —
kompletní tabulky „Podrobný popis událostí v řízení“). Vytěžené labely:
[data/infosoud-ciselniky.json](data/infosoud-ciselniky.json) (včetně nových
`atributNs`/`atributKs`).

## 1. Detail události — co obnáší zobrazení

**Proveditelné bez překážek.** Endpoint `udalost/vyhledej` vrací hlavičku
řízení + `atributy` (pole `{typ, hodnota}`) + `navazneVeci`. Každý typ události
má vlastní slovník atributů (ověřeno na vzorcích, potvrzeno nápovědou SPA):

| Událost | Pozorované atributy |
|---|---|
| ZAHAJ_RIZ | `PREDM_RIZ`, `PRED_VEC` (+ `NAVRH_PR` u EPR) |
| NAR_JED | `JED_DRUH`, `JED_SIN`, `JED_D_ZAC` (datum+čas!), `JED_D_Z_V`, `JED_VYSLED`, `JED_ZRUS` |
| ZRUS_JED | `JED_SIN`, `JED_D_ZAC`, `JED_D_Z_V`, `JED_ZRUS` (bez druhu a výsledku) |
| VYD_ROZH | `ROZH_KON`, `ROZH_VYS`, `ROZH_D_VYD`, `ROZH_D_DIK`, `ROZH_DR_RO`, `ROZH_D_VYP`, `ROZH_ZRUS`, `ROZH_DR_S` (dle slovníku i `ROZH_D_PM`, `ROZH_D_ZRU`) |
| ST_VEC_VYR | `STAV_VECI`, `ST_VEC_D_D` (dle slovníku i `ST_VEC_UPR`, `ST_VEC_ZVR`) |
| POD_OP_PR | `OP_DRUH`, `OP_D_PODA`, `OP_ROZ_PR`, `OP_D_VYD`, `OP_D_ROZH` (slovník zná ~15 `OP_*`) |
| ODES_SPIS | `OD_SP_D_OD`, `OD_SP_KOMU`, `OD_SP_UCEL`, `VR_SP_D_VR` (slovník i `OD_SP_D_OV` — očekávané vrácení) |
| VRAC_SPIS | `VR_SP_D_VR` + echo `OD_SP_*` |
| ODVOLANI / NAD_RIZENI | `NADRIZENY_SOUD` + `navazneVeci[typ=SPISOVA_ZNACKA_NADRIZENEHO_SOUDU]` |
| PREVD_SPIS | `PREVD_D_OD`, `PREVD_SOUD`, `PREVD_SPZN` (dle slovníku) |
| NS obecně | `SENAT`, `D_SENAT`, `SLOZENI_SENATU`, `ODVOL_SOUD`, `PR_VEC_NS`, … |

**Pravidla vykreslení v SPA** (`DetailUdalostiComponent` — přebrat/vylepšit):

- Nadpis = název události (NS má vlastní sadu názvů) + `datumUdalost` (dd.MM.yyyy).
- Volitelná věta „popisu“ (`udalostPopis`; u NS skoro vždy prázdná → nezobrazovat).
- Atributy v pořadí z API; label ze slovníku s overridy: NS → `atributNs.*`
  (fallback obecný), KS → jen `OP_D_PODA` („Datum doručení OP soudu“).
- Speciality: `NAVRH_PR` je **flag** — zobrazuje se jen label, a jen když
  `hodnota == "#TRUE"`. Všechny hodnoty procházejí pipe `split('|') → join(', ')`
  (kvůli `SLOZENI_SENATU` — jména soudců oddělená `|`).
- `navazneVeci` se vypisují **za** atributy, u `DOVOL_RIZ` **před** nimi;
  položky jsou odkazy na detail řízení (u nás → náš detail spisu; bookmark
  ikonka stavu se hodí i sem).
- SPA nijak neřeší hodnotu `-` (vypíše ji) — my ji už dnes normalizujeme na
  „neuvedeno“/vynechání, v tom pokračovat.
- Ano/Ne hodnoty (`ROZH_KON`, `JED_ZRUS`, `ROZH_ZRUS`) chodí jako text
  „Ano“/„Ne“ — lze zobrazit jako badge.

**Náklady na requesty:** detail = 1 request (cache-first, stejný vzor jako
detail spisu: cooldown 5 min, stale banner). První zobrazení detailu události
z už načteného spisu = max 1 upstream request.

**Deep-link do SPA:** resolver SPA posílá query parametry **1:1 do API** —
`/InfoSoud/detail-udalosti?<case params>&druhUdalosti=…&poradiUdalosti=…&organizaceId=…`;
u cizí události navíc `cisloSenatuId`/`druhVeciId`/`bcVecId`/`rocnikId`
(vyplněné jen tam, kde se liší od mateřského spisu) a `udalostId` (viz níže).
Ověřeno: detail cizí události (ODVOLANI) jde načíst i „přepnutím“ na cizí spis
jako hlavní parametry.

## 2. Robustnost odkazu na událost (`poradi`)

Zjištěné vlastnosti identifikace:

- **`poradi` je pořadové číslo záznamu ve spisu na straně soudu (ISAS), ne
  globální ID.** V řadě jsou velké díry (2 T 101/2024: 1, 4, 6, 12, …, 53 —
  ~45 % čísel chybí) — neveřejné typy záznamů se nevydávají, ale číslo
  spotřebují.
- `poradi` je **pořadí zápisu, ne pořadí událostí v čase**: NAR_JED dostane
  číslo při nařízení (datum má budoucí), takže globálně podle `poradi` řadit
  nelze. V rámci jednoho dne ale zápis ≈ kauzální pořadí (viz §3).
- **Cizí události nesou `poradi` z číselné řady cizího spisu** (ODVOLANI má
  `poradi` 1 z řady 5 To 320/2025 u MS). Identita události je tedy
  (spis-vlastník záznamu, druh, poradi), kde spis-vlastník = `znackaId`.
- **`udalostId`**: v API existuje, ale u ISAS soudů je `null`; vyplněné jen u
  EPR spisů (CEPR backend; globální ID, i složené „12956732;186“). Server-side
  lookup detailu stejně jede přes (spis, druhUdalosti, poradiUdalosti) —
  `udalostId` nelze použít jako univerzální stabilní klíč.
- **Přečíslování**: přímo neověříme (nemáme historické snapshoty), ale
  mechanismus (interní pořadová čísla, retroaktivní zásahy do spisu) mu
  nasvědčuje a uživatel je pozoroval. Nutno předpokládat, že `poradi` se může
  časem posunout.

**Detekce driftu je levná a spolehlivá:** z timeline známe (druh, datum,
zruseno) události. Když detail vrátí `UDALOST_0000` (nenalezeno), nebo vrátí
`datumUdalost` ≠ očekávané datum, došlo k posunu.

**Strategie (rozhodnuto 2026-07-19, revize téhož dne):**

1. URL události: `/spis/<soud>/<znacka>/udalost/<id>`, kde `id` = **náš PK**
   v tabulce událostí. Adresování přes `poradi` bylo zavrženo: cizí událost
   nese `poradi` z číselné řady cizího spisu a koliduje s vlastní řadou
   (reálně: v timeline 2 T 101/2024 je vlastní ZAHAJ_RIZ s `poradi` 1
   i cizí ODVOLANI s `poradi` 1 z řady 5 To 320/2025), a zároveň cizí
   události **musí mít vlastní stránku u spisu A** — infoSoud ji má
   (detail-udalosti se spisem A + `*Id` parametry spisu B) a zobrazuje na ní
   informaci o vazbě „ke spisu A běží opravné řízení B“; jen odkázat na spis
   B by o tuto informaci přišlo. PK pokrývá vlastní i cizí záznamy jednotně.
   `poradi` zůstává jen (a) párovací klíč cache↔API a (b) řadicí kritérium
   (§3). URL není trvalý permalink a nesmí se tak prezentovat.
   - Stránka musí ověřit, že `id` patří ke spisu z URL (PK je globální
     autoincrement) — jinak 404; mimochodem tím nejde enumerovat cizí spisy.
   - Detail cizí události se z API dotahuje autentickou formou SPA:
     parametry spisu A + `druhUdalosti`/`poradiUdalosti` +
     `cisloSenatuId`/`druhVeciId`/`bcVecId`/`rocnikId`/`organizaceId`
     spisu B (`*Id` pole SPA vyplňuje jen tam, kde se liší od spisu A;
     ověřeno, že funguje i dotaz se spisem B jako hlavními parametry).
2. Stránka události čte náš DB záznam; nesoulad se **nezjišťuje na úrovni
   requestu na stránku**, ale až při dotažení detailu z API — porovnáním
   (druh, datum) se stavem v DB.
3. Neshoda / nenalezeno (`UDALOST_0000`) → uživatel je vrácen na detail
   spisu s hláškou, že byla zjištěna **narušená integrita dat**, a výzvou
   k aktualizaci. Aktualizace načte přehled spisu, vyhodnotí, že paměť
   událostí je nesmyslná, **zahodí ji** a vygeneruje nové záznamy událostí
   (= nové URL; PK-based URL přežívají běžný upsert-refresh, zahazují se jen
   tady).
4. Interní odkazy (notifikace, watchlist) = tentýž PK, žádný druhý klíč.

## 3. Řazení událostí stejného dne

- API vrací `udalosti` řazené podle data, ale **v rámci dne v nahodilém
  pořadí** (2 T 101/2024, 22. 4. 2026: API pořadí VYD_ROZH(43), ST_VEC_VYR(44),
  NAR_JED(41) — jednání až za rozsudkem). **SPA seznam vůbec nesortuje** —
  infosoud.gov.cz to zobrazuje stejně nelogicky.
- `poradi` = pořadí zápisu → **v rámci jednoho dne je správným tie-breakerem**
  (41 → 43 → 44 dává: nařízeno jednání → vydáno rozhodnutí → vyřízena věc).
  Hypotéza uživatele potvrzena daty.
- Pozor na cizí události: jejich `poradi` je z jiné číselné řady, takže
  **primární klíč řazení musí být datum a teprve pak `poradi`** — jinak by
  odvolačky s malým cizím `poradi` odskákaly na začátek seznamu. Zbytková
  nepřesnost: sdílí-li cizí událost den s vlastními, je její pozice v rámci
  dne stejně nahodilá (cizí řada je vůči vlastní bezvýznamná); volitelné
  zjemnění = v rámci dne vlastní záznamy podle `poradi`, cizí za ně:
  `(datum, jeCizí ? 1 : 0, poradi)`.
- Změna je lokální (dnes řadíme jen `strcmp` podle data v `buildEvents()`),
  nezávislá na zbytku analýzy — lze nasadit hned.

## 4. Rozpad JSON → tabulky (návrh)

Zásada: **surový JSON per zdroj zůstává** (filozofie snapshotů — auditní stopa
a možnost přegenerování), tabulky jsou **odvozená projekce**, kterou sync při
každém refreshi přestaví. Tím se elegantně řeší i přečíslování `poradi`.

### Tabulka `proceeding_event`

- `id` PK; `proceeding_id` FK → `proceeding` (událost existuje jen u načteného
  spisu — tady FK nevadí, na rozdíl od vazeb).
- `source` (`infosoud` | do budoucna další) — události z různých zdrojů se
  nesmí při syncu vzájemně mazat.
- `event_code` (NAR_JED, …), `event_order` (`poradi`, NULL kde není),
  `upstream_id` (`udalostId` z CEPR, NULLable, string — bývá složené),
  `event_date`, `cancelled`.
- **Vlastník záznamu u cizích událostí:** `znackaId` ≠ mateřský spis →
  identitní sloupce `ref_court_kod` + `ref_registry_norm` + `ref_senate` +
  `ref_bc_number` + `ref_year` (NULL = vlastní událost). Bez FK — cizí spis
  nemusí být načtený.
- **Dvoustupňový záznam (thin → full):** už načtení přehledu spisu založí
  řádky **všech** událostí, jen se základními údaji z timeline (druh, datum,
  `poradi`, zruseno, případný cizí vlastník) — „thin“. Detail se dočítá až
  při rozkliknutí. Úplnost se pozná bez zvláštního flagu, stejným vzorem
  jako per-source sloupce na `proceeding`:
  - `detail_fetched_at IS NULL` → thin záznam (detail nikdy nedotažen),
  - `detail_fetched_at` vyplněné + `detail_json IS NULL` → dotazováno,
    upstream detail nemá (nebude se zkoušet při každém zobrazení),
  - obojí vyplněné → plný záznam (timestamp zároveň slouží pro stale/cooldown
    logiku jako u spisu).
- **Sync timeline = upsert, ne slepý replace:** příchozí události se párují
  na existující řádky podle (`source`, `event_code`, `event_order`, ref
  pětice); shoda → aktualizace datum/zruseno (při změně data zahodit detail —
  podezření na přečíslování), nové → insert thin, osiřelé → delete. PK (a tedy
  interní odkazy i URL) tak běžný refresh nemění. Při zjištěné narušené
  integritě (viz §2) se paměť událostí spisu zahazuje celá a staví znovu.
- Řazení pro UI: `ORDER BY event_date, (ref_court_kod IS NOT NULL), event_order`
  (datum vždy první — viz §3).
- Unikát: párovací identita syncu je (`proceeding_id`, `source`,
  `event_code`, `event_order`, ref pětice); jako DB constraint ji držet jen
  mezi vlastními záznamy (ref NULL — NULL sloupec v unikátu duplicity
  nechrání, což tu je výjimečně žádoucí chování). URL adresuje výhradně PK.

### Tabulka `proceeding_relation` (N:M vazby spisů)

Klíčový požadavek: vazba smí ukazovat na spis, který **není načtený** (nemá
`proceeding.id`), a musí unést i cíle mimo soudní soustavu (PRED_VEC typu
„1 ZT 63/2024“ — rejstřík státního zastupitelství, který ani nejde načíst).
Proto **žádné FK na `proceeding`; oba konce jsou spisovková identita**:

- `src_court_kod`, `src_registry_norm`, `src_senate`, `src_bc_number`,
  `src_year` — zdrojový spis (u infosoud vazeb vždy náš načtený spis).
- `dst_court_kod` (**NULLable** — PRED_VEC soud nenese; dopočítává se jako
  dnes: jednoznačná shoda v evidenci → soud, jinak heuristika/neznámý),
  `dst_registry_norm` (i mimo číselník), `dst_senate`, `dst_bc_number`,
  `dst_year`.
- `relation_type` — číselník (admin-editovatelný, vzor `registry`):
  `PRED_VEC`, `ODVOLANI`, `NAD_RIZENI`, `DOVOL_RIZ`, `NAVAZNA_VEC`,
  `PREVD_SPIS`, + ruční typy (souběžné řízení spolupachatele, souběh
  trestní/civilní, …). Vazby jsou **směrové** (předchozí→následující,
  prvoinstanční→odvolací); „obousměrnost“ = dotaz přes oba konce (index na
  src i dst pěticí), ne duplicitní reverzní řádky.
- `source` (`infosoud` | `manual` | výhledově `isir`, …) + `note` (volný text
  k ručním vazbám) + `created_at`.
- **Sync mazací pravidlo:** refresh z infosoudu přestaví jen řádky
  `source='infosoud'` daného spisu; `manual` vazby přežívají vždy.
- Unikát: `(src pětice, dst pětice, relation_type, source)` — sloupce jsou
  NOT NULL kromě `dst_court_kod`; u MariaDB unikát s NULL sloupcem nechrání
  duplicity → `dst_court_kod` v unikátu nahradit `COALESCE`/generovaným
  sloupcem (`dst_court_kod_key = IFNULL(dst_court_kod,'')`), detail vyřešit
  při implementaci.
- Efekt „obousměrného provázání“: detail spisu B zobrazí i vazby, kde je B na
  `dst` straně (dnes to nejde — vazby známe jen z JSONu spisu A). Bookmark
  ikonky se na oba směry napojí lookupem `dst`/`src` pětice v `proceeding`.

### Dopad na stávající kód

- `SpisPresenter::buildRelated()`/`buildEvents()` se zjednoduší na čtení
  tabulek; parsing JSONu se přesune do syncu (`ProceedingSyncService`).
- Backfill: jednorázový přepočet z uložených `infosoud_json` (25 spisů) —
  žádné nové requesty na justici.
- ISIR data se do `proceeding_event` zatím nepromítají (výpisy lustrace
  nenesou timeline) — sloupec `source` na to je připraven.

## 5. Bonusové nálezy (mimo zadání, ale relevantní)

- **Kadence aktualizací:** nápověda tvrdí „údaje přicházejí z jednotlivých
  soudů 1× denně“, ale **empiricky to neplatí** (dlouhodobé pozorování
  uživatele: např. zrušení jednání se objeví kdykoli během dne). Deklaraci
  z nápovědy nebrat jako podklad pro návrh monitoringu — skutečnou kadenci
  změn bude nutné vypozorovat z vlastních dat (timestampy scanů), per soud
  se může lišit.
- **Modul InfoJednání**: `jednani/vyhledej` hledá **nařízená jednání jen na
  následujících 30 dnů**, podle spisovky NEBO jednací síně + data (validace
  `JEDNANI_VALIDATION_0005/0006`, „nelze vyhledávat proběhlá jednání“
  `…_0007`; „pro značku není v následujících 30 dnech jednání“ `JEDNANI_0002`).
  Pole `jednani[]` v událostech bylo zatím vždy prázdné — přesný tvar payloadu
  doplnit při implementaci modulu Jednani (náš pokus s event-parametry vrátil
  `…_0008`).
- Slovník atributů doplněn o NS/KS overridy do
  [data/infosoud-ciselniky.json](data/infosoud-ciselniky.json).
