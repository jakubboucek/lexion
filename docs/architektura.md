# Architektura

> Tento dokument popisuje **existující** architekturu projektu. Cíle, plány
> a úvahy o budoucím rozvoji žijí v [roadmap.md](roadmap.md) — sem se z nich
> přesouvá popis až po implementaci. Kde je existující stavba záměrnou
> přípravou na plánovanou funkci, je to poznamenáno odkazem na roadmapu.
> Vzniklo z brainstormingu 2026-07-17; provozní detaily a konvence viz
> CLAUDE.md v kořeni repa.

## Základní rozhodnutí

- **Monolit Nette** — web UI + CLI tooly (`bin/`, spouštějí se zatím ručně;
  crony přijdou s monitoringem, viz roadmap). Hosting: webhosting s FTP
  deployem, produkce **lex.ion.cz**.
- **Multi-user od začátku, minimalisticky** — účty zakládá obsluha ručně
  (CLI), žádná samoobslužná registrace. Uživatelé: „já + pár známých“.
- **Public-first sbírka toolů** — login-wall není dominanta. Úvodní stránka
  = přímo hlavní tool („Google style“: logo + formulář spisovky, nic dalšího;
  původní představa HP jako dashboardu s kartami toolů se opustila — toolů je
  málo a vyhledání spisu je dominantní use-case). Veřejný je i detail spisu
  a statistiky `/stats`; tool vyžadující identitu přesměruje na login až při
  otevření. Za loginem: uživatelský obsah (oblíbené spisy, Panel) a budoucí
  systémové stránky (nastavení notifikací ap.).

## Kanonizace URL

**Kanonizaci URL řeší framework sám** (`autoCanonicalize`, zapnutý; po `action*()`
a před obsloužením signálu porovná `Presenter::canonicalize()` adresu požadavku
s tou, kterou by pro týž request vygeneroval, a při neshodě pošle 301 — u GET/HEAD,
mimo AJAX). Nekanonické tvary, které router matchuje, proto **nejsou druhou živou
URL**: `/about` i `/about/default` → 301 `/o-projektu`, `/home[/default]` → 301 `/`,
`/stats/default` → 301 `/stats`, `/panel/dashboard[/default]` → 301 `/panel`.
Ověřovat je nutné **přihlášeně** — login-wall ve `startup()` běží dřív než
kanonizace, takže nepřihlášenému klientovi se všechny tvary panelu jeví jako 302
na `/sign/in`.

**Ručně se proto kanonizuje jen to, co router vědět nemůže**: hodnotu parametru
přepsanou podle číselníku nebo doménového parseru. Jediné takové místo je
`SpisPresenter::resolveCase()` — soud (starý infosoud kód → `court.slug`) a slug
spisovky (velikost písmen, tvar rejstříku). Nové ruční redirecty „kvůli tvaru URL“
do presenterů nepřidávej; pokud se zdá, že stránka má dvě adresy, ověř to nejdřív
kódem odpovědi.

## Doménové moduly

Každý zdroj dat je samostatný modul v `web/app/Model/<Domain>/`:

| Modul | Obsah | Stav |
|-------|-------|------|
| `Infosoud` | klient neoficiálního API (viz [infosoud-api.md](infosoud-api.md)), link builder, enums typů událostí/atributů/kolegií, `InfosoudHearing` | ✅ (monitoring zatím není) |
| `Hearing` | evidence jednání z infoJednání (viz [infojednani-api.md](infojednani-api.md)) — tabulky `hearing`/`hearing_observation`/`hearing_room`, CLI sken → import → párování | ✅ |
| `Isir` | insolvenční rejstřík — má **oficiální API**, není třeba scrapovat | neexistuje (viz roadmap); data v `case_file.isir_json` pocházejí z jednorázového importu měsíčních výpisů (importní tool byl po splnění účelu odstraněn) |
| `Nss` | archivace rozsudků NS/NSS | neexistuje (viz roadmap) |

Poznámka k pojmenování: modul jednání se jmenuje **`Hearing`**, ne „Jednani“ —
jednak platí pravidlo anglických názvů interních struktur (žádné české názvy
v kódu), jednak je pojem záměrně obecnější: nemusí jít jen o soudní jednání,
ale i o jiné typy organizovaného svolání.

Parser spisovky žije v `Model/Spisovka/` a číselníky v `Model/Codelist/` —
nejsou to zdroje dat, ale sdílená doména napříč moduly.

Zamýšlený společný cyklus všech modulů — **fetch → snapshot (raw) → diff →
notifikace** — dnes končí u snapshotů (raw JSON per zdroj); diff a notifikace
jsou plán (viz roadmap). Presentation vrstva je dělená podle publika
(public / modul `Panel`), ne podle domén; doménové moduly do ní přidávají
presentery/šablony do příslušné zóny.

## Typové entity a repositories

Model nepracuje s anonymními strukturami: **z repositories vycházejí jen
typové entity**, nikdy `ActiveRow` ani `Selection` (převod dokončen
2026-08-05; PHPStan level 8 to hlídá bez jakéhokoli ignore). Zbylé výskyty
`ActiveRow` v kódu jsou `assert()` u `Selection::insert()` a `instanceof`
před hydratací — tedy uvnitř repositories.

De/hydrataci dělá **[jakubboucek/hydrator](https://github.com/jakubboucek/hydrator)**
(vybrán po srovnávacím POC čtyř variant — metodika a čísla jsou trvale
v [issue #10](https://github.com/jakubboucek/lexion/issues/10)). **Balíček je
vlastní projekt autora aplikace**, který vznikl přímo pro potřeby Lexionu:
když si vývoj vyžádá změnu rozhraní nebo novou funkci, řeší se to
připomínkou/issue v balíčku, ne obcházením v aplikaci. `HydratorFactory` je
registrovaná v `web/config/services.neon` s formátem **`NetteDatabase`**
(hodnoty jsou už otypované na obou stranách — `DateTimeImmutable`, `bool`,
`DateInterval` — takže instance procházejí bez konverzí) a časovou zónou
**Europe/Prague** (každý datum-čas se normalizuje deterministicky, nezávisle
na php.ini). Pozn.: v NEONu nefunguje `::class`, název formátu se píše jako
string.

### Konvence entit

- třída implementuje prázdný marker interface `JakubBoucek\Hydrator\Entity`,
  má **typované public properties**, žádný konstruktor, žádné magic
  gettery/settery;
- **žádné atributy**, dokud si je nevynutí výjimka. Dnes je v projektu jen
  jedno takové místo: `#[Type\Date]`/`#[Type\Time]` u sloupců DATE/TIME
  (`Hearing`) — bez `#[Type\Time]` by hodnota šla do TIME sloupce jako plný
  `Y-m-d H:i:s` s truncation note. Druhé bývalo `#[Name('proceeding_id')]`
  u `CaseFileEvent::$caseFileId`, kde property předbíhala název sloupce;
  přejmenování tabulek (2026-08-20) atribut zrušilo — **mapování jmen řeš
  vždycky renamem sloupce, ne atributem**;
- mapování jmen je jinak konvenční: `camelCase` property ↔ `snake_case` sloupec;
- **kompozitní výstupy a stavové detekce** patří do entity jako metody nebo
  virtual get-hook properties (hydrator je v obou směrech přeskakuje). Tak
  vznikly párovací a identitní klíče — `Hearing::key()`, `HearingRoom::key()`,
  `CaseFileEvent::pairingKey()`: dřív je skládaly dva různé kusy kódu
  (příchozí data vs. uložené řádky) a mohly se rozejít;
- **enum jen tam, kde množinu drží i DB** (CHECK constraint) — `CourtLevel`,
  `CourtRegion`, `HearingRoomKind`, `CourtBinding`, `ObservationSource`.
  Naopak `relation_type.code` nebo `case_file_relation.source` zůstávají
  `string`: číselník je editovatelný obsluhou a řádek s kódem mimo enum je
  legitimní stav, ne chyba hydratace;
- **JSON sloupce se typují podle původu obsahu** (rozhodnuto 2026-08-23):
  - **cizí verbatim snapshoty se netypují** (`infosoud_json`/`isir_json`,
    `CaseFileEvent::$detailJson`, `HearingObservation::$rawJson`) — snapshot
    filozofie, strukturu čtou projekce. (Typový pohled `InfosoudCaseOverview
    extends RawJsonObject` existoval do 2026-08-26; zanikl s derivovanými
    sloupci — hodnoty se dnes nečtou z payloadu za běhu vůbec.)
    Hydrator ≥ 0.7 sice nabízí `RawJsonValue` (byte-exact,
    nullable property), ale **nenasazovat**: validuje JSON už při hydrataci,
    takže poškozený payload by shodil načtení entity a rozbil žurnálový flow
    `payload_unreadable`, který potřebuje spis nejdřív načíst;
  - **naše vlastní JSON struktury se typují** přes `Struct\JsonObject`
    (whole-payload, `public array $value`, prázdný ⇔ NULL sloupec, property
    nenullable — NULL sloupec hydratuje na prázdnou instanci; **zachovává
    null hodnoty uvnitř dokumentu**, na rozdíl od `BaseStruct`/
    `DynamicObject`). Takto typované: `LogEntry` (`data`/`context`/
    `result_data`/`files` — mapa `files` na nullech závisí) a
    `CaseFileJournalEntry` (`state_before`/`state_after`/`context`;
    `captureState()` vrací rovnou `JsonObject`, vnořené raw payloady
    zůstávají string hodnoty bajtově přesně);
- **generovaný sloupec nemá property** (`dst_court_key`, `room_key`) —
  hydratace neznámé sloupce ignoruje, ale extrakce by property poslala do
  INSERTu a MariaDB by zápis odmítla;
- entita **neví o databázi** — hydrataci dělá repository přes
  `HydratorFactory::for()`.

### Konvence repositories

- ven jdou **jen entity** (`?Entity` / `list<Entity>`);
- **zápis bere entitu**: díky partial-update sémantice (extrahují se jen
  *inicializované* properties) je částečně vyplněná entita přirozený patch —
  viz `Authenticator` (rehash hesla), `bin/create-user.php` (upsert),
  `HearingRoomRepository::touchSeen()`. Inicializovaná `null` property se do
  UPDATE opravdu dostane (ověřeno);
- o **invarianty se stará repository**, ne volající: `FavoriteRepository::add()`
  si `position` přiřadí vždy sama (pořadí 1..n v bucketu je invariant
  repository), zatímco `groupId` respektuje, když ho volající vyplnil;
- `fromDataSet(...)->collectList()` pro seznamy, `collectMap(keyBy: …)` pro
  číselníkové lookupy, lazy `fromDataSet()` bez `collect*` (`EntitySet`) pro
  dávkové CLI průchody (`streamAll()`, `streamWithSource()`);
- **metody se jmenují podle domény, ne podle tabulky** (`findByCaseFile()`,
  `SpisovkaFactory::fromCaseFile()`), i když třída zatím nese starý název;
- `save()`/upsert záměrně nevznikl — dvojice `insert()`/`update()` drží
  call-site čitelný a žádný konzument dispatch podle `id` nepotřebuje.

### Ptaní se částečné entity

Čtení neinicializované typované property je fatální `Error`, takže platí:

- **nenullable property → nativní `isset()` / `??=`** (odpovídá přesně,
  nepotřebuje hydrator);
- **nullable property s patch sémantikou → `Hydrator::isInitialized()`** —
  `isset()` neodliší uložený `null` („nastav sloupec na NULL“) od nevyplněného
  („nesahej na to“). Jediné takové místo je dnes `groupId`
  v `FavoriteRepository::add()`;
- **prázdný patch v repository → prázdný výsledek `toData()`** (extrahuje se
  tak jako tak, takže je to nejlevnější možná pojistka);
- **prázdnost mimo hranici úložiště → `getInitializationState()`**
  (`Empty`/`Partial`/`Complete`); v aplikaci zatím není takové místo;
- **na otázku „co entita nese“ `toData()` nepoužívej** — mluví jazykem
  sloupců, ne domény. Jako pojistka *před zápisem* je naopak na místě.

### Pasti, na které se narazilo

- **Ztráta `ActiveRow::ref()`** je hlavní past převodu: Nette ji dávkuje na
  jeden dotaz za celou Selection, entita žádnou traverzaci nemá → naivní
  náhrada je N+1. Řešení je **dávkový lookup v repository**
  (`CaseFileRepository::findByIds()`). Pravidlo: **před úpravou domény si
  najdi `->ref(`/`->related(` v konzumentech.** *Otevřená úvaha autora:*
  postavit objekt držící živé spojení na `Selection`, který při iteraci vrací
  navzájem provázané entity (lazy, v duchu `EntitySet`) — zatím jen nápad,
  nic se podle něj nerozhoduje.
- **Šablony dostávají view-modely, ne entity** — jinak se vazba na DB schéma
  přesune do Latte. Detail spisu dostává skaláry (`bool $isFavorite`,
  `?string $favoriteName`, `?DateTimeImmutable $infosoudAt`), Dashboard pole
  `['id', 'name']`. Výjimka je hotový read-only objekt číselníku
  (`Court` se do šablon spisu předává celý).
- **`{varType}` není důkaz o použití šablony:** `udalost.latte` deklarovala
  `$event` jako `ActiveRow` a zároveň ho opravdu používala; odstranění
  proměnné z presenteru se projevilo až jako Tracy warning v prohlížeči —
  `composer check` ani latte-lint to nechytily. **U každé upravované šablony
  si vypiš skutečná použití, ne jen `{varType}`.**
- **Testy hydratace se nepíšou** (rozhodnutí 2026-08-06): mechaniku mapování
  testuje balíček sám na sobě a drift entita ↔ schéma DB se projeví hlasitě
  (`HydrationException` s názvem pole) při prvním runtime dotyku; projektový
  roundtrip test by vyžadoval DB (u nás se skipuje) a nic navíc by nechytil.
  Jediný skutečný projektový invariant pokrývá `RegistryCodelistConsistency.phpt`.

## Data: spisovna (`case_file`)

> **Změna paradigmatu (2026-07-27):** tato data se dřív označovala jako
> „měkká cache“ — to už neplatí a pojem cache se opouští (viz CLAUDE.md,
> *Terminologie*). Spisovna je základní stavební kámen klíčových
> funkcí (notifikace, sledování, historie); analýzy se dělají nad ní, ne
> nad infoSoudem. Oportunistický způsob plnění neznamená postradatelnost:
> tabulka se nikdy nemaže a řádky se svévolně neodstraňují — nabalují na
> sebe metadata (vazby jednání, oblíbené, budoucí atributy). Starší výskyty
> slova „cache“ v dokumentech se převádějí postupně. „Spisovna“ je jen
> koncepční pojem — kód i DB pojmenovávají obsah, ne kontejner: entita
> `CaseFile` a tabulka `case_file` (viz CLAUDE.md, *Terminologie*).

- **Tabulka `case_file`** (migrace 2026-07-18-02/03, přejmenovaná z
  `proceeding` migrací 2026-08-20-00): ve sloupcích vyhledávací klíče identity
  **(soud, rejstřík, senát, číslo, ročník)** a derivované sloupce (viz níže),
  zbytek v nativních JSON sloupcích per zdroj (`infosoud_json`/`infosoud_at`,
  `isir_json`/`isir_at`). Struktura JSON se nechává volná; co potřebuje UI
  dotazovat, se **projektuje do tabulek a sloupců**. `infosoud_json` je
  **verbatim odpověď** overview endpointu — do 2026-08-26 do něj sync
  přidával syntetický klíč `firstEventDetail` (relikvie z doby před tabulkou
  `case_file_event`), ten je zrušen a z uložených payloadů odstraněn
  (migrace `structures/2026-08-26-00`, krok 4).

### Derivovaná data: raw JSON se za běhu nečte

Rozhodnutí 2026-08-26. Raw JSON sloupce jsou **jen pro zápis, kontroly
a analýzy** — zobrazení stránky je nesmí dekódovat. Všechno, co UI potřebuje,
se materializuje při zápisu:

- `case_file.subject` (PREDM_RIZ prvního vlastního eventu), `status`,
  `status_date`, `intake_kind`,
- `case_file_event.hearing_at`/`hearing_room`/`hearing_type` z atributů
  `JED_D_ZAC`/`JED_SIN`/`JED_DRUH` detailu (`hearing_room` je 255 znaků —
  soudy tam píšou i celé věty o místě konání).

Obojí zavádí jediná migrace `structures/2026-08-26-00-case-file-derived-columns.sql`
(sloupce + backfill + úklid `firstEventDetail` v jednom pevném pořadí; **spustit
před deployem kódu**, který už sloupce vyžaduje).

Překlad payload → patch entity vlastní **`CaseFile\CaseSummaryExtraction`**
(statická, bez DI). Zapisuje se tam, kde se zapisuje zdroj: overview sloupce
v `CaseFileSyncService`, `subject` na konci `CaseFileProjectionService::apply()`
(čte stav event řádků *po* zápisu, takže se srovná i po smazání či posunu
prvního záznamu) a hearing sloupce spolu s `detail_json` (seed v projekci
i lazy fetch v `EventDetailService`). Nevyplněná hodnota se zapisuje jako
**explicitní NULL**, ne jako nedotčená property — spis, který přestane uvádět
předmět, musí sloupec vyčistit.

**Jediná záměrná výjimka** jsou atributy NS (SENAT, SLOZENI_SENATU,
ODVOL_SOUD, PR_VEC_NS): čte je `CaseSummaryService::attributesOf()`
z `detail_json` prvního eventu, protože se týkají zlomku spisů a čtyři
NS-only sloupce v `case_file` by modelovaly detail události v tabulce spisů.
Druhé místo, kde se JSON čte i nadále, je **stránka detailu události** —
tam je variabilita atributů podstatou stránky. Soulad sloupců s payloadem
hlídají kontroly `case-summary-drift` a `hearing-columns-drift` v System →
Kontrola dat.

- **Projekční tabulky `case_file_event` / `case_file_relation`** (+ číselník
  `relation_type` s reverzními labely pro pohled z druhé strany vazby) — staví
  je `CaseFileProjectionService` při každém syncu z raw JSON; detaily
  událostí se dočítají lazy (thin/full řádky, `detail_fetched_at`).
  Zdůvodnění návrhu: [analyza-udalosti.md](analyza-udalosti.md).
  Běh projekce je od 2026-08-22 rozdělen na **`plan()` + `apply()`**:
  plán je čistý diff uloženého stavu proti čerstvému payloadu (bez zápisů,
  testovaný v `web/tests/Model/CaseFileProjectionPlan.phpt`), apply zapíše
  přesně to, co plán říká. Vazby se od téhož data **diffují** místo
  delete-all-and-rebuild (nezměněné řádky přežívají i s `id`/`created_at`).
  Dry-run budoucích opravných akcí = vypsat plán místo aplikace
  (viz [navrh-integrita-dat.md](navrh-integrita-dat.md), krok 4).
  Od 2026-08-23 projekce materializuje i **vnořené záznamy vícetermínových
  jednání** (`jednani[]` u NAR_JED/ZRUS_JED) jako vlastní řádky s odkazem
  na agregát přirozeným klíčem `parent_event_order` (kód i `cancelled` dědí
  po rodiči — vlastní příznak vnořený záznam nemá); identita záznamu zůstává
  (kód, poradi, owner), takže přesun mezi agregáty i osamostatnění je plain
  update — viz [infosoud-api.md](infosoud-api.md).

### Žurnál ztrát dat (`case_file_journal`)

Tabulka `case_file_journal` (migrace 2026-08-22-00) zaznamenává **anomálie,
při kterých se destruuje nebo zahazuje** — nikoli běžné změny; refresh, který
nic neztrácí, nezapisuje nic. Zapisuje `CaseFileJournalService`, čtenáře zatím
nemá (analýza Adminerem; UI vznikne, až reálné výskyty ukážou objem a povahu).
Principy (rozhodnuto 2026-08-22):

- **Zapisují se fakta, ne interpretace** — „řádek zmizel“, nikdy „upstream
  přečísloval“; typy záznamů (`JournalEntryType`, v DB hlídané CHECKem):
  `projection_data_loss` (destruktivní běh projekce — zmizelé události,
  zahozené detaily při posunu data, ubrané vazby; jeden záznam na běh),
  `event_detail_rejected` (stažený detail popisuje jiný záznam —
  `IntegrityBroken`; odmítnutý payload je jediný autentický důkaz
  přečíslovaného spisu, jaký kdy budeme mít), `case_response_rejected`
  (odmítnutá odpověď spisu, dnes nesouhlas ročníku — past „2098 vrátí 1998“),
  `payload_unreadable` (nedekódovatelný uložený payload).
- **`state_before`/`state_after` = úplné JSON snapshoty stavu spisu**
  (řádek `case_file` + všechny `case_file_event` + `case_file_relation`
  ze strany src), serializované Hydratorem ve formátu `Format\Json` —
  názvy polí jsou názvy properties entit (`caseFileId`, …), takže snapshoty
  nikdy nebude potřeba migrovat a hydratují se zpět do týchž entit
  (základ budoucího restore nástroje; **obnova záměrně neimplementována**).
  Raw JSON sloupce jsou ve snapshotu vnořené jako string, bajtově přesně.
  V entitě jsou snapshoty i `context` typované jako `Struct\JsonObject`
  (2026-08-23; prázdná instance ⇔ NULL sloupec — „refusal nic nezapsal“
  = prázdný `stateAfter`), `captureState()` vrací rovnou `JsonObject`.
- **Timing snapshotu „před“:** sync přepisuje `infosoud_json` dřív, než běží
  projekce — plán i snapshot se proto pořizují **před prvním zápisem**
  (jinak by vznikla chiméra: staré události + nová hlavička). Reprojekce
  (`projectInfosoud()`) hlavičku nemění, takže si snapshot i žurnál řeší
  sama; sync (`CaseFileSyncService`) orchestruje plan/snapshot/apply/žurnál
  ve své transakci. Záznam o provedené destrukci je **ve stejné transakci**
  jako destrukce; záznamy o odmítnutích (nic se nezapsalo) transakci nemají.
- **První reálná úroda (2026-08-25, hromadný refresh 221 spisů):** 15
  záznamů `projection_data_loss`, dva vzory. (a) **12× zahozená vazba
  PRED_VEC — nejspíš false positive:** vazba s `dst_court_kod = NULL` byla
  v témže běhu nahrazena identickou vazbou s doplněným soudem (cílový spis
  mezitím přibyl do spisovny, takže lookup „právě jedna shoda“ začal
  nacházet; soud je součástí identity vazby, diff to proto provede jako
  drop + insert). Data se nezničila, zpřesnila se — kandidát na filtr:
  zahozenou vazbu nejournalovat, když ji tentýž běh nahrazuje vazbou
  lišící se jen přechodem `dst_court_kod` NULL → hodnota. Pozor ale:
  samotné doplňování soudu podle jediné shody je zpochybněno (kolizní
  spisovky, [issue #14](https://github.com/jakubboucek/lexion/issues/14) —
  viz [analyza-udalosti.md](analyza-udalosti.md), tabulka
  `case_file_relation`). (b) **3× zahozená událost ST_VEC_VYR — skutečné
  mazání na straně justice:** infoSoud při obživnutí a novém rozhodnutí
  věci starý stavový marker z timeline odstraní; ostatní záznamy se
  **nepřečíslují** (empirie viz [analyza-udalosti.md](analyza-udalosti.md),
  §2). Informace „věc byla poprvé vyřízena dne X“ pak žije už jen
  v before snapshotu žurnálu.
- Sync merge (`Sync\CaseFileMergeService`) žurnál nevolá — je aditivní,
  při podpisu přečíslování celý spis přeskočí (`SyncProblem*`), nic neničí.
- **Tabulka spisů je nezávislá na uživatelských datech** — ukládají se
  i řízení, která nikdo nesleduje (jednorázově zobrazená). Ta se
  **neaktualizují průběžně**, drží jen poslední známý stav + per-zdroj časy.
  Stará cache (> 1 měsíc) při zobrazení = banner „vidíš starou verzi, systém
  ji neudržuje“ + tlačítko jednorázové aktualizace (5min cooldown per spis).
- **Plnění:** `bin/infosoud-fetch.php` (jeden spis přes `InfosoudClient`)
  a průběžně samotný web (tlačítko „Otevřít“ na HP, ruční refresh na detailu).
  Základ cache (~13 tis. řízení v `isir_json`) pochází z jednorázového importu
  měsíčních výpisů ISIR lustrace; importní tool byl po splnění účelu odstraněn.

### Evidence deterministických neúspěchů (`case_lookup_miss`)

Tabulka `case_lookup_miss` (migrace 2026-08-28-00) dokumentuje **identity spisů,
na které zdroj deterministicky neodpověděl** — jeden řádek na pětici+zdroj,
s `outcome` (CHECK): `not_found` (infosoud HTTP 400), `rejected` (odmítnutý
dotaz — např. Nc na krajském soudu, `InfosoudRejectedException`),
`year_mismatch` (odpověď s jiným ročníkem — past dvojčíslí před rokem 2000).
Zapisuje `CaseFileSyncService` při každém fetchi **včetně webových** (stopa po
scrapování formuláře je žádoucí); opakovaný neúspěch zvyšuje `attempts`
a posouvá `last_attempt_at`. Principy (rozhodnuto 2026-08-28):

- **Záznam je informace, ne verdikt.** Trvalost se nikdy neukládá, počítá ji
  čtenář (`CaseLookupMissRepository::isPermanent()`): `rejected`/`year_mismatch`
  jsou trvalé z povahy; `not_found` až když byl ověřen v kalendářním roce
  **pozdějším než ročník** (uzavřený ročník už nedoroste — a rozhoduje rok
  ověření, ne dnešek: miss ověřený ještě v ročníkovém roce mohla řada mezitím
  předběhnout), nebo když v téže řadě existuje potvrzený spis s vyšším číslem
  (číslo bylo přeskočeno = reálný, ale nezveřejněný spis).
- **Transientní chyby (výpadek, timeout) se nezapisují nikdy** — jdou jako
  instantní záznam do aplikačního logu (`case_file` / `infosoud-unavailable`,
  status `failed`), aby bylo chování upstreamu monitorovatelné.
- **Úspěšný fetch dříve zaznamenané identity miss maže** + instantní log
  `case_file` / `miss-resolved`: u běžícího ročníku jde o dorostlou řadu,
  u uzavřeného o **zveřejnění dříve neveřejného spisu** — analyticky cenná
  událost.
- Záměrně **bez FK na `case_file`** (pointa je, že spis neexistuje) a jako
  **oddělená tabulka, ne flag** — `case_file` zůstává evidencí existujících
  spisů a žádný její čtenář se o missech nemusí dozvědět.
- Konzument: `bin/infosoud-fetch.php --skip-exists` (přeskočí artefakt kdykoli
  stažený **a** identity s trvalým missem — režim pro skeny starých ročníků)
  a adaptivní sken číselných řad níže.

### Adaptivní sken číselných řad (`case_series_scan`)

`bin/infosoud-scan-series.php` nad službou `CaseSeriesScanService` (2026-08-28)
systematicky proskenuje **blok číselné řady** (soud × rejstřík × senát × ročník,
od `number_from`) — vyplní díry a najde konec řady s **logaritmickým počtem
sond** místo salvy not-foundů. Detailní návod, empirie a algoritmus:
[navrh-sken-rad.md](navrh-sken-rad.md). Klíčové z pohledu architektury:

- **Jádro je čistý stavový automat** `CaseSeriesEndSearch` (bez DB/sítě,
  testy `web/tests/Model/CaseSeriesEndSearch.phpt` + `CaseSeriesScanState.phpt`):
  odhad-skok → galloping → bisekce, konec potvrzený K souvislými missy,
  tolerance děr; při zužování už K-potvrzené horní hranice stačí 2 missy.
- **Každá sonda jde přes `CaseFileSyncService`** — hit přistane v `case_file`,
  miss v `case_lookup_miss` (výše); už držené spisy i trvalé missy se přeskočí
  (memoizace, 0 requestů). Detail 1. události se **ve výchozím stavu stahuje**
  (naplní `subject` + PRED_VEC, hit = 2 requesty), `--no-first-event` ho vynechá
  — pro trestní řady zbytečný, pro civilní podstatný.
- **Ledger `case_series_scan`** (migrace 2026-08-28-01): identita bloku
  (soud, rejstřík, senát, ročník, `number_from`; UNIQUE — víc pásem/senát je
  víc řádků), `scanned_at` = běh proběhl, `number_confirmed_end`/`confirmed_at`
  se zapíšou **jen když skener konec potvrdí** (předčasný stop či tvrdý strop
  `to` = jen `scanned_at`, žádný nepřesný závěr). Nejvyšší číslo se
  neduplikuje — je v `case_file`; pozdější řádek s číslem nad potvrzeným koncem
  z dat sám odhalí podstřelený sken. Bez FK na `case_file`, oddělená tabulka.
- **Nesenátní rejstříky se odmítají** (blocklist INS, EPR, ICM, EXE, NT, NC —
  globální/blokové řady, hustý sken 1..N by pálil desetitisíce not-foundů).
- **Rozhodnutí každé sondy** jdou do JSONL běhu (log kind `case_file` /
  `series-scan`) pro zpětný audit; scheduler losuje sondy napříč řadami
  (stealth — žádná vzestupná řada v logu poskytovatele).

## Pravidla načítání (šetrnost k justici)

- **1 zobrazení detailu spisu = max 2 requesty na justici** (přehled řízení
  + detail první události s předmětem), a to jen když spis není v cache (nebo
  na ruční refresh s cooldownem). Když se systém o spisu dozví jen okrajově
  (výpis ISIR ap.), nedotahuje se nic.
- **Detail jednotlivé události** se dočítá až on-demand při návštěvě stránky
  události (tlačítko „Stáhnout podrobnosti“ / signál, cooldown 5 min).
- **Související spisy se NIKDY nenačítají automaticky** — v detailu jsou jen
  odkazy; cizí spis se stáhne až při kliknutí (návštěvě jeho detailu).

## Tool: parser spisovky (na HP)

Vstupní pole pro spisovku vloženou jako celý text + výběr soudu. Tool původně
žil na `/spisovka`, později se přesunul přímo na HP; `/spisovka` dnes vrací 404
a zbyly jen JSON endpointy `validate` a `resolve`. (Plánované přejmenování
reliktního pojmu „spisovka“ v kódu: viz roadmap.)

**Od 2026-08-16 je tool Vue island** (první ve webu; zbytek aplikace zůstává
serverem renderované HTML). Rozhodnutí a jeho důvod: formulář je natolik
interaktivní, že jeho serverová a živá verze se nutně rozcházely — proto na HP
**neexistuje serverem renderovaný formulář** ani fallback bez JS. Server dodává
jen data (endpointy, prefill, číselník soudů) a odbavuje:

- `Spisovka:validate` — živá validace při psaní (stateless GET),
- `Spisovka:resolve` — submit; drží pravidla „urči soud, odmítni NSS, odkaž jen
  na spis, o kterém víme, že existuje“ a vrací cíl navigace nebo chyby po polích
  (POST, same-origin).

Detaily stavového modelu islandu (kdy se odpověď smí použít, proč se requesty
neruší, jak vypadá panel) jsou v CLAUDE.md, sekce *Tool spisovky*.

- **Parser (tokenizace, ne jeden regex):** normalizace (trim,
  case-insensitive, sjednocení mezer, ořez interpunkce na krajích), pak rozpad
  na runy číslic/písmen. Podporované tvary: klasický `24 NC 3601 / 2024`
  i ISIR tvar s prefixem soudu `KSPH 60INS19742/2024` (bez mezer). Pozor na
  víceslovné rejstříky („P a Nc“ — infosoud API `P A NC`).
- **Validace s nápovědou:** rejstřík se validuje proti číselníku; neznámý
  rejstřík nabídne textově nejbližší existující (levenshtein). Chyby
  konkrétní: „není uveden rok“, „rejstřík ‚ACB‘ neexistuje, mysleli jste
  ‚ACK‘?“, „toto nevypadá jako spisová značka“.
- **Detekce soudu ze značky (pipeline pravidel → zúžení kandidátů):**
  1. prefix soudu (ISIR kódy KSPH/MSPH/… → mapování na infosoud kódy, číselník
     `court_prefix`),
  2. úroveň rejstříku (Cdo jen NS → rovnou NS; INS jen KS → nabídku omezit
     na KS),
  3. senátní mapování (např. „60 INS“ = KS Praha) — číselník `senate_rule`,
     vědomě neúplný, skládá se postupně.

  Za pipeline parseru následují ještě dva zdroje kandidátů (viz CLAUDE.md,
  *Tool spisovky*): spisovna `case_file` a evidence jednání `hearing`
  (soud síně — jen napovídá, nikdy nepřepíše ruční volbu; a UI nesmí tvrdit
  předvýběr, který se kvůli tomu nekonal). Návrh je otevřený dalším pravidlům.
- **Výběr soudu = Tom Select combobox** s textovým filtrováním („trut“ →
  Trutnov). Tím je pokryto i původně plánované „fulltextové hledání soudů
  podle města“ ze zadání — samostatný tool ani aliasy měst v číselníku nejsou
  potřeba. V islandu ho obaluje Vue komponenta; nahrazovat ho Vue comboboxem
  se **záměrně nechystá** (fulltext, optgroups, klávesnice a ~90 ř. CSS by se
  psaly znovu).
- **Tlačítka:** „Otevřít“ (přes `resolve` ověří existenci řízení — spisovna,
  jinak fetch, který ji naplní — a vede na `/spis/<soud>/<slug>`), „InfoSoud“ (tupý překladač
  na deep-link, formáty ověřené pro OS/KS/VS/NS — viz
  [infosoud-api.md](infosoud-api.md); bez určeného soudu chyba „zvolte
  soud“), „Najít příslušný soud“ (zatím disabled placeholder — Tool 2,
  viz roadmap).

## Stavové bookmark ikonky spisu

Define `Presentation/@bookmark.latte`, zobrazují se před sp. zn. (např.
v seznamu souvisejících řízení); UI se vyhýbá technickému slovu „cache“
(říkáme „načtený/evidovaný spis“). Progrese outline → filled, sada Material
Symbols Light:

- `bookmark-outline` — spis ještě není v systému načtený,
- `bookmark-added-outline` — načtený, ale průběžně neudržovaný,
- `bookmark-added` — udržovaný/pravidelně aktualizovaný (**zatím se
  negeneruje** — čeká na monitoring, viz roadmap),
- `bookmark-heart` — spis mezi oblíbenými/sledovanými uživatele (**zatím se
  negeneruje** — napojení na oblíbené/budoucí sledování se teprve rozhodne).

Stav „starý uzavřený spis, monitoring zastaven i přes žádost uživatele“ se na
úrovni ikonky záměrně nerozlišuje.

## Číselníky

Vše v DB (seed migrace 2026-07-18-00 + pozdější); admin UI zatím není —
editace Adminerem (role admin viz roadmap).

**Cache číselníků (`Codelist\CodelistCache`):** tabulky `court`, `registry`,
`court_prefix` a `relation_type` se čtou přes serializovaný **snapshot**
(`CodelistSnapshot` → per-table Set třídy s entitami + lookup mapami),
cachovaný přes `nette/caching` v `temp/cache`. Repositories mají nezměněné
veřejné API, ale každý lookup je array access na předpočítané mapě — po
zahřátí cache **0 SQL dotazů na číselníky** (detail spisu klesl z 93 na
29 dotazů). Řazení (`findAll`) je upečené z DB při buildu (česká kolace se
v PHP nereprodukuje). Invalidace à la DI kontejner: v debug módu file
dependencies na entity/Set třídy (auto-refresh při změně souboru), na
produkci platí do deploye (purge `temp/cache`); žádné TTL. **Ruční
číselníková migrace bez deploye proto vyžaduje smazat cache** (namespace
`_App.Codelist` v `temp/cache`) — každá číselníková migrace to musí mít
v hlavičce. Klíčové lookupy jsou case-insensitive, ale (na rozdíl od dřívější
DB kolace `*_ci`) accent-sensitive — záměrné zpřísnění. `senate_rule`
a `hearing_room` se záměrně necachují (čtou se výjimečně). Odůvodnění
návrhu: [analyza-ciselniky.md](analyza-ciselniky.md).

- **Soudy (`court`):** infosoud kód, název, úroveň, nadřízený soud; později
  přibyly `slug` (naše URL, migrace 2026-07-18-05) a `region` (soudní kraj
  dle členění 1960, migrace 2026-07-19-01 — sloupec PHA/STC/JIC/ZPC/SCE/VYC/
  JIM/SEM, NULL pro celostátní NS/NSS, + enum `Codelist\CourtRegion`
  s českými labely; hierarchie typ soudu → kraj → okres se staví z něj, ne
  přes `parent_kod`). **ISIR prefixy** (KSPH → KSSTCAB, …) jsou v samostatné
  tabulce `court_prefix`.
- **Rejstříky (`registry`):** kód + `code_norm` + `slug` (tři formy, viz
  CLAUDE.md), úroveň soudu, popis — seed z
  [data/rejstriky-soudu.json](data/rejstriky-soudu.json); párování
  case-insensitive.
- **Senátní mapování (`senate_rule`):** rejstřík + číslo senátu → soud(y).
  **Pozor: ani senáty INS nejsou celostátně unikátní** (ověřeno na ISIR
  datech — např. senát 60 INS mají současně KS Praha, MS Praha i pobočka
  Pardubice). Tabulka proto připouští více řádků na senát: jediný řádek
  = soud určen, více řádků = zúžení kandidátů. Seed pro INS: vytěženo
  z měsíčních výpisů zveřejněných spisovek ISIR (7 měsíců 2025–2026,
  ~13,8 tis. spisovek → 109 párů senát×soud, 73 senátů, z toho 29
  víceznačných); migrace `2026-07-18-01-relax-senate-rule-seed-ins.sql`.
- **Vazby řízení (`relation_type`):** kódy vazeb s labely pro oba směry
  (`label`/`label_reverse`), viz [analyza-udalosti.md](analyza-udalosti.md).
- **Jednací síně (`hearing_room`):** číselník síní z infoJednání, viz
  [infojednani-api.md](infojednani-api.md).

## Zdroj číselníku rejstříků (druhů věcí)

Stát publikuje oficiální **seznam soudních rejstříků s popisy a příslušností
k úrovni soudu** (okresní/krajský/vrchní/NS/NSS) — použil se k obohacení
interního číselníku (infosoud API vrací jen holé kódy). Strojově čitelný
snapshot (staženo 2026-07-17, 115 položek):
[data/rejstriky-soudu.json](data/rejstriky-soudu.json), zdroj:
[msp.gov.cz — Seznam rejstříků soudů](https://msp.gov.cz/en/web/msp/statisticke-udaje-z-oblasti-justice/-/clanek/seznam-rejstriku-soudu).
Pozor na drobné rozdíly zápisu vůči infosoud API (MSP „P a Nc“ × API
„P A NC“; API kóduje vše uppercase) — párovat case-insensitively.

## Známé quirky infosoudu

Viz [infosoud-api.md](infosoud-api.md) — tam je kompletní katalog (nenalezeno
= HTTP 400 s `RIZENI_0000`, tvary requestů per úroveň soudu, detail události
s atributy, tři mechanismy vazeb mezi řízeními, NS alias `NSJIMBM` + senát 0
v znackaId, zrušené události v timeline). Záložka „Informace o jednání“ má
vlastní API na jiné doméně — kompletně zmapované
v [infojednani-api.md](infojednani-api.md).
