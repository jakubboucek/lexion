# Analýza: detail události, robustnost odkazů, rozpad JSON do tabulek

> **Stav: ✅ implementováno 2026-07-19** (migrace 2026-07-19-03/04 + datová
> migrace `migrations/data/2026-07-19-00-project-proceeding-events-relations.php`,
> `CaseFileProjectionService`, stránka `/spis/<soud>/<znacka>/udalost/<id>`).
> Dokument zůstává jako zdůvodnění návrhu; místa, kde se finální
> implementace od návrhu odchýlila, jsou označena poznámkami „⚙️ realita“.

Analýza proveditelnosti z 2026-07-19 (v době sepsání bez implementace). Podklady: 8 detailů
událostí různých typů staženo z API (spis 2 T 101/2024 OS Praha 1 + ODVOLANI
z 5 To 320/2025 MS Praha), pozorování chování SPA (detail události/řízení,
zpracování deep-linků) a nápověda SPA (`/napoveda` —
kompletní tabulky „Podrobný popis událostí v řízení“). Zachycené labely:
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

**Pravidla vykreslení v SPA** (pozorováno na detailu události — přebrat/vylepšit):

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
z už načteného spisu = max 1 upstream request. *⚙️ realita:* detail **první
vlastní události** se dnes tahá už při syncu spisu (kvůli předmětu řízení)
a projekce ho rovnou naseeduje do řádku
(`CaseFileProjectionService::seedFirstEventDetail()`) — pro ni je to tedy
0 requestů. Detail se předává jako argument, ne přes `infosoud_json`
(historický klíč `firstEventDetail` viz §4).

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
  časem posunout. *⚙️ empirie 2026-08-25:* první historické snapshoty už
  existují (žurnál) a zachytily opačný jev — **mazání záznamu bez
  přečíslování**, viz níže.

### Empirie: smazání ST_VEC_VYR nepřečísluje ostatní záznamy (žurnál 2026-08-25)

První hromadný refresh spisovny (221 spisů) zachytil do žurnálu
(`projection_data_loss`) tři případy, kdy **infoSoud smazal událost
ST_VEC_VYR z timeline**. Vzor je u všech tří shodný: věc byla rozhodnuta
(`VYD_ROZH` + `ST_VEC_VYR`), rozhodnutí padlo (opravný prostředek, stav
„Obživlá věc“), soud rozhodl znovu — a infoSoud staré `ST_VEC_VYR` smazal
a na konec řady zapsal nové. `ST_VEC_VYR` se tedy nechová jako historická
událost, ale jako **stavový marker aktuálního vyřízení, který se při novém
rozhodnutí přesouvá** (staré `VYD_ROZH` přitom v historii zůstává). Případy:
28 C 140/2025 (vyřízeno 25. 6. 2025 → znovu 24. 8. 2026), 4 T 83/2024
(14. 2. 2025 → 10. 8. 2026), 3 T 6/2026 (4. 5. 2026 → 17. 8. 2026), vše
OS Plzeň-město.

Podstatné pro diff algoritmus projekce: **žádný přeživší záznam se
nepřečísloval.** Ve všech třech spisech si všechny ostatní vlastní události
(dohromady 37) podržely přesně své `poradi`, druh i datum; po smazaném
záznamu zůstala v řadě díra, čísla se nerecyklují a nové záznamy pokračují
vyššími čísly (s dírami po neveřejných záznamech: …42 → 44 → 47). Jediná
další změna byla legitimní aktualizace téhož záznamu (NAR_JED překlopený na
`zruseno`). Klíč (spis-vlastník, druh, `poradi`) se tedy v tomto scénáři
chová jako stabilní identita a „zmizelé `poradi`“ znamená skutečné smazání
záznamu upstreamem, ne posun číslování — chytřejší algoritmus (místo
zahodit-a-postavit) na tom může stavět. Vzorek jsou zatím 3 spisy jednoho
soudu (ISAS); přečíslování jako jev tím není vyvráceno, jen zatím
nepozorováno.

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
   - Detail cizí události se z API dá dotáhnout autentickou formou SPA
     (parametry spisu A + `druhUdalosti`/`poradiUdalosti` + `*Id` pole
     spisu B) — ověřeno ale, že funguje i dotaz se spisem B jako hlavními
     parametry. *⚙️ realita:* implementace používá **druhou variantu**
     (`SpisPresenter` přepne na spis B z `ref_*` sloupců,
     `InfosoudClient::fetchEventDetail()` `*Id` parametry nezná); SPA forma
     s `*Id` zůstala jen pro **odchozí deep-link**
     (`InfosoudLinkBuilder::eventDetailUrl()`).
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

> **TODO / stav k 2026-07-27:** bod 3 je zapojený jen z poloviny. Neshoda
> typu/data spouští flash + redirect s výzvou k aktualizaci, ale samotná
> aktualizace projekci pouze upsertuje — `CaseFileProjectionService::
> resetInfosoudEvents()` existuje, ale **nikde se nevolá**. A záměrně:
> ukázalo se, že „zahodit a přegenerovat“ paměť událostí **nelze** — na
> `case_file_event` se už párují další data (zejména potvrzené vazby
> jednání z infoJednání, `bin/hearing-bind.php` potvrzuje proti `JED_*`
> detailům událostí) a zahozením by vznikla nevratná ztráta. Mechanismus
> obnovy integrity je potřeba navrhnout znovu bez destrukce (řeší se
> v některé z dalších iterací). Pozn.: „nenalezeno“ (`UDALOST_0000`)
> dnes integritní flow nespouští — `InfosoudClient` ho vrací jako `null`
> a presenter to bere jako „upstream detail nemá“; ostatní 400 kódy
> (`UDALOST_0001`, validace) vyhazují `InfosoudApiException` a nic se
> neukládá (opraveno 2026-07-27, dřív se jako „nemá detail“ betonovala
> jakákoli 400).
>
> **Doplněk 2026-08-22:** každá destrukce paměti událostí (zmizelé řádky,
> zahozené detaily při posunu data, ubrané vazby) i každý odmítnutý detail
> se nově zaznamenává do žurnálu `case_file_journal` s úplnými before/after
> snapshoty — viz [architektura.md](architektura.md), *Žurnál ztrát dat*.
> Návrh nedestruktivní obnovy se opře o reálné výskyty v něm.
>
> **Doplněk 2026-08-23 — kontrola typu je bezzubá:** empiricky ověřeno
> (sondy nad 10 T 3/2026 MS Praha), že API detail události hledá **jen podle
> (spis, poradi)** a `druhUdalosti` vůbec nevaliduje — `typUdalosti`
> v odpovědi je pouhé **echo** requestu (i schválně nesmyslný `VYD_ROZH`
> vrátil `JED_*` atributy jednání s `typUdalosti=VYD_ROZH`). Porovnání typu
> v `EventDetailService::describesSameRecord()` tedy projde vždy; skutečnou
> integritní ochranou je **jen porovnání data**. A dále: neexistující
> `poradi` nevrací `UDALOST_0000`, ale **prázdnou obálku** (echo typu,
> `datumUdalost` chybí, `atributy` prázdné) — shodou okolností ji date-check
> korektně odmítne (prázdné datum ≠ datum řádku → IntegrityBroken +
> žurnál), ale nespoléhat na to jinde.

## 3. Řazení událostí stejného dne

- API vrací `udalosti` řazené podle data, ale **v rámci dne v nahodilém
  pořadí** (2 T 101/2024, 22. 4. 2026: API pořadí VYD_ROZH(43), ST_VEC_VYR(44),
  NAR_JED(41) — jednání až za rozsudkem). **SPA seznam vůbec nesortuje** —
  infosoud.gov.cz to zobrazuje stejně nelogicky.
- `poradi` = pořadí zápisu → **v rámci jednoho dne je správným tie-breakerem**
  (41 → 43 → 44 dává: nařízeno jednání → vydáno rozhodnutí → vyřízena věc).
  Hypotéza uživatele potvrzena daty. *(Pozdější korekce: potvrzena jen pro
  tento spis — viz protipříklad níže; `poradi` je tie-breaker poslední
  instance, ne spolehlivá vnitrodenní chronologie.)*
- Pozor na cizí události: jejich `poradi` je z jiné číselné řady, takže
  **primární klíč řazení musí být datum a teprve pak `poradi`** — jinak by
  odvolačky s malým cizím `poradi` odskákaly na začátek seznamu. Zbytková
  nepřesnost: sdílí-li cizí událost den s vlastními, je její pozice v rámci
  dne stejně nahodilá (cizí řada je vůči vlastní bezvýznamná); volitelné
  zjemnění = v rámci dne vlastní záznamy podle `poradi`, cizí za ně:
  `(datum, jeCizí ? 1 : 0, poradi)`.
- *⚙️ realita:* nasazeno v SQL — `CaseFileEventRepository` řadí
  `ORDER BY event_date, (ref_court_kod IS NOT NULL), event_order`, přesně
  podle zjemněné varianty výše.

### Protipříklad: `poradi` v rámci dne selhává (zjištění 2026-08-10, zatím neřešeno)

Spis **8 To 35/2024 KS Plzeň** (case_file 13079) vyvrací hypotézu, že
`poradi` je univerzálně správná vnitrodenní chronologie. Den 13. 2. 2024
řadíme podle `poradi` takto: ST_VEC_VYR(3) → VYD_ROZH(4) → ZRUS_JED(6,
zrušeno) → NAR_JED(7) — tedy **vyřízení věci a rozhodnutí před jednáním**,
které téhož dne proběhlo. Procesně správně: NAR_JED → VYD_ROZH → ST_VEC_VYR.

Proč `poradi` nesedí:

- **NAR_JED byl do ISAS zapsán znovu až po rozhodnutí** (dostal `poradi` 7).
  V datech jsou stopy přepisu/přečíslování: v seznamu chybí `poradi` 2 a 5,
  zrušený záznam jednání má `poradi` 6 a pod-záznam `jednani` u NAR_JED
  odkazuje na `poradiUdalosti: 5`. Každý přepis záznamu tedy rozbije
  zápisovou chronologii.
- **Praxe zapisovatele se liší soud od soudu**: tady ST_VEC_VYR(3) předchází
  VYD_ROZH(4) — přesně obráceně než u OS Praha 1 ve vzorku 2 T 101/2024.
- Nejde o kopírování infosoudu: API vrací (a SPA zobrazuje) ZAHAJ_RIZ,
  ZRUS_JED, VYD_ROZH, ST_VEC_VYR, NAR_JED, ST_VEC_ODS — selhává náš vlastní
  tie-break podle `poradi`, ne převzaté pořadí.

**Návrh řešení (odloženo — nejdřív nasbírat víc protipříkladů):** vložit
před `poradi` **sémantický rank druhu události** podle procesní logiky,
klíč `(datum, jeCizí, typeRank, poradi)`. Rank zhruba: ZAHAJ_RIZ → jednání
(ZRUS_JED, NAR_JED) → VYD_ROZH → ST_VEC_VYR → ST_VEC_ODS/ostatní stavové;
kódy se sporným procesním pořadím (ST_VEC_OBZ, POD_OP_PR, …) dostanou
střední default, ať mezi sebou rozhodne `poradi` a nevznikne nová
nelogičnost opačným směrem. Na vzorku 2 T 101/2024 dává rank stejný správný
výsledek, dosud fungující případy se nerozbijí. Preferovaná implementace:
sort v PHP v `CaseFileEventRepository::findByCaseFile()` (rank mapa jako
konstanta, žádná migrace); zavrženo `ORDER BY FIELD(...)` (doménový seznam
v SQL stringu) i persistovaný `sort_rank` sloupec (migrace + resync,
overkill na zobrazovací pořadí).

## 4. Rozpad JSON → tabulky (návrh, ✅ implementováno — odchylky viz ⚙️)

Zásada: **surový JSON per zdroj zůstává** (filozofie snapshotů — auditní stopa
a možnost přegenerování), tabulky jsou **odvozená projekce**, kterou sync při
každém refreshi přestaví. Tím se elegantně řeší i přečíslování `poradi`.
*⚙️ realita:* do 2026-08-26 zásadu porušoval sám sync — do `infosoud_json`
vkládal syntetický klíč **`firstEventDetail`** (odpověď `udalost/vyhledej`
pro první vlastní událost), takže sloupec byl sloučeninou dvou odpovědí.
**Zrušeno:** stažený detail putuje do `plan()`/`apply()` jako samostatný
argument, a když ho refresh nestahoval (`--no-first-event`), bere se
`PRED_VEC` z už uloženého `detail_json` prvního vlastního eventu — plán ty
řádky načítá tak jako tak. Z uložených payloadů klíč odstranila migrace
`structures/2026-08-26-00` (krok 4); `infosoud_json` je zase čistý snapshot
`rizeni/vyhledej`. Předmět řízení dnes nese sloupec `case_file.subject`
(viz [architektura.md](architektura.md), *Derivovaná data*).

### Tabulka `case_file_event`

- `id` PK; `case_file_id` FK → `case_file` (událost existuje jen u načteného
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
  jako per-source sloupce na `case_file`:
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
  integritě (viz §2) se paměť událostí spisu zahazuje celá a staví znovu
  (*⚙️ realita:* zahazování **není zapojené a nebude v této podobě** — viz
  TODO v §2).
- Řazení pro UI: `ORDER BY event_date, (ref_court_kod IS NOT NULL), event_order`
  (datum vždy první — viz §3).
- Unikát: párovací identita syncu je (`case_file_id`, `source`,
  `event_code`, `event_order`, ref pětice); jako DB constraint ji držet jen
  mezi vlastními záznamy (ref NULL — NULL sloupec v unikátu duplicity
  nechrání, což tu je výjimečně žádoucí chování). URL adresuje výhradně PK.
  *⚙️ realita:* stejného efektu se dosáhlo jinak — generovaný sloupec
  `own_event_order` (= `event_order` jen pro vlastní záznamy, u cizích NULL)
  a unikát `uq_case_file_event_own(case_file_id, source, event_code, own_event_order)`;
  ref sloupce v unikátu nejsou.

### Tabulka `case_file_relation` (N:M vazby spisů)

Klíčový požadavek: vazba smí ukazovat na spis, který **není načtený** (nemá
`case_file.id`), a musí unést i cíle mimo soudní soustavu (PRED_VEC typu
„1 ZT 63/2024“ — rejstřík státního zastupitelství, který ani nejde načíst).
Proto **žádné FK na `case_file`; oba konce jsou spisovková identita**:

- `src_court_kod`, `src_registry_norm`, `src_senate`, `src_bc_number`,
  `src_year` — zdrojový spis (u infosoud vazeb vždy náš načtený spis).
- `dst_court_kod` (**NULLable** — PRED_VEC soud nenese; dopočítává se jako
  dnes: jednoznačná shoda v evidenci → soud, jinak heuristika/neznámý),
  `dst_registry_norm` (i mimo číselník), `dst_senate`, `dst_bc_number`,
  `dst_year`. *⚙️ realita:* heuristika byla **záměrně zrušena** (commit
  „Stop inventing the court of a predecessor case“ + datová migrace
  `2026-07-26-00-fix-pred-vec-court.sql`) — soud se vyplní **výhradně** při
  právě jedné shodě v evidenci, jinak zůstává NULL; fallback „tentýž soud“
  vyráběl nepravdivé vazby. *⚠️ revize 2026-08-25:* i pravidlo „právě jedna
  shoda v evidenci“ je nyní považováno za rizikové — původní domněnka, že
  spisové značky jsou napříč soudy víceméně unikátní, je chybná (kolize jsou
  naopak časté, viz identita spisu = pětice včetně soudu) a evidence pokrývá
  jen zlomek řízení, takže jediná shoda u nás nic nedokazuje o jedinečnosti
  v realitě. Zamýšlený směr je [issue #14](https://github.com/jakubboucek/lexion/issues/14):
  soud netvrdit, ale u vazby bez soudu vést uživatele na vyhledávací
  formulář s **navrženým** (ne tvrzeným) stejným soudem a vysvětlením, že
  infoSoud soud neposkytuje.
- `relation_type` — číselník (admin-editovatelný, vzor `registry`):
  `PRED_VEC`, `ODVOLANI`, `NAD_RIZENI`, `DOVOL_RIZ`, `NAVAZNA_VEC`,
  `PREVD_SPIS`, + ruční typy (souběžné řízení spolupachatele, souběh
  trestní/civilní, …). Vazby jsou **směrové** (předchozí→následující,
  prvoinstanční→odvolací); „obousměrnost“ = dotaz přes oba konce (index na
  src i dst pěticí), ne duplicitní reverzní řádky. *⚙️ realita:* sedmý kód
  je **`SOUVISEJICI`** — není ruční, ale automatický fallback projekce pro
  libovolný neznámý cizí kód události v timeline; ruční typy zatím
  neexistují (přijdou s ručními vazbami). Číselník navíc nese sloupce
  **`label`/`label_reverse`** — pohled „z druhé strany“ se řeší reverzním
  labelem při čtení, dotaz přes oba konce zůstává.
- `source` (`infosoud` | `manual` | výhledově `isir`, …) + `note` (volný text
  k ručním vazbám) + `created_at`.
- **Sync mazací pravidlo:** refresh z infosoudu přestaví jen řádky
  `source='infosoud'` daného spisu; `manual` vazby přežívají vždy.
- Unikát: `(src pětice, dst pětice, relation_type, source)` — sloupce jsou
  NOT NULL kromě `dst_court_kod`; u MariaDB unikát s NULL sloupcem nechrání
  duplicity → `dst_court_kod` v unikátu nahradit `COALESCE`/generovaným
  sloupcem. *⚙️ realita — vyřešeno:* generovaný sloupec se jmenuje
  `dst_court_key` (`IFNULL(dst_court_kod,'')`) a je součástí `uq_relation`
  (migrace `2026-07-19-04-create-relation-tables.sql`).
- Efekt „obousměrného provázání“: detail spisu B zobrazí i vazby, kde je B na
  `dst` straně (dnes to nejde — vazby známe jen z JSONu spisu A). Bookmark
  ikonky se na oba směry napojí lookupem `dst`/`src` pětice v `case_file`.

### Dopad na stávající kód

- `SpisPresenter::buildRelated()`/`buildEvents()` se zjednoduší na čtení
  tabulek; parsing JSONu se přesune do syncu (`CaseFileSyncService`).
  *⚙️ realita:* metody se dnes jmenují `buildEventsView()`/`buildRelatedView()`,
  projekci staví `CaseFileProjectionService`.
- Backfill: jednorázový přepočet z uložených `infosoud_json` (tehdy 25 spisů) —
  žádné nové requesty na justici. *⚙️ realita — proveden* datovou migrací
  `migrations/data/2026-07-19-00-project-proceeding-events-relations.php`.
- ISIR data se do `case_file_event` zatím nepromítají (výpisy lustrace
  nenesou timeline) — sloupec `source` na to je připraven.

## 5. Bonusové nálezy (mimo zadání, ale relevantní)

- **Kadence aktualizací:** nápověda tvrdí „údaje přicházejí z jednotlivých
  soudů 1× denně“, ale **empiricky to neplatí** (dlouhodobé pozorování
  uživatele: např. zrušení jednání se objeví kdykoli během dne). Deklaraci
  z nápovědy nebrat jako podklad pro návrh monitoringu — skutečnou kadenci
  změn bude nutné vypozorovat z vlastních dat (timestampy scanů), per soud
  se může lišit.
- **Modul InfoJednání**: hledá nařízená jednání podle spisovky NEBO jednací
  síně + data (validace `JEDNANI_VALIDATION_0005/0006`, „nelze vyhledávat
  proběhlá jednání“ `…_0007`; „pro značku není v následujících 30 dnech
  jednání“ `JEDNANI_0002`). *⚙️ realita — dořešeno:* API má vlastní SPA na
  infojednani.gov.cz, payload je kompletně zmapovaný a implementovaný
  (sken → import do tabulek `hearing*`), viz
  [infojednani-api.md](infojednani-api.md); domnělý limit „jen 30 dnů
  dopředu“ se nepotvrdil (SPA to tak jen nabízí, API přijme libovolné
  budoucí datum). Pole `jednani[]` v událostech bylo zatím vždy prázdné.
- Slovník atributů doplněn o NS/KS overridy do
  [data/infosoud-ciselniky.json](data/infosoud-ciselniky.json).
