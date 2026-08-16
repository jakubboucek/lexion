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
| `Isir` | insolvenční rejstřík — má **oficiální API**, není třeba scrapovat | neexistuje (viz roadmap); data v `proceeding.isir_json` pocházejí z jednorázového importu měsíčních výpisů (importní tool byl po splnění účelu odstraněn) |
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
- **žádné atributy**, dokud si je nevynutí výjimka. Dnes jsou v projektu jen
  dvě taková místa: `#[Type\Date]`/`#[Type\Time]` u sloupců DATE/TIME
  (`Hearing`) — bez `#[Type\Time]` by hodnota šla do TIME sloupce jako plný
  `Y-m-d H:i:s` s truncation note — a jediný `#[Name('proceeding_id')]`
  u `CaseFileEvent::$caseFileId` (property už nese cílový název, viz CLAUDE.md
  *Terminologie*; DB vlna atribut smaže, nepřejmenuje property);
- mapování jmen je jinak konvenční: `camelCase` property ↔ `snake_case` sloupec;
- **kompozitní výstupy a stavové detekce** patří do entity jako metody nebo
  virtual get-hook properties (hydrator je v obou směrech přeskakuje). Tak
  vznikly párovací a identitní klíče — `Hearing::key()`, `HearingRoom::key()`,
  `CaseFileEvent::pairingKey()`: dřív je skládaly dva různé kusy kódu
  (příchozí data vs. uložené řádky) a mohly se rozejít;
- **enum jen tam, kde množinu drží i DB** (CHECK constraint) — `CourtLevel`,
  `CourtRegion`, `HearingRoomKind`, `CourtBinding`, `ObservationSource`.
  Naopak `relation_type.code` nebo `proceeding_relation.source` zůstávají
  `string`: číselník je editovatelný obsluhou a řádek s kódem mimo enum je
  legitimní stav, ne chyba hydratace;
- **raw JSON sloupce se netypují** (`infosoud_json`/`isir_json`,
  `HearingObservation::$rawJson`) — snapshot filozofie, strukturu čtou
  projekce;
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
  (`ProceedingRepository::findByIds()`). Pravidlo: **před úpravou domény si
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

## Data: spisovna (`proceeding`)

> **Změna paradigmatu (2026-07-27):** tato data se dřív označovala jako
> „měkká cache“ — to už neplatí a pojem cache se opouští (viz CLAUDE.md,
> *Terminologie*). Spisovna je základní stavební kámen klíčových
> funkcí (notifikace, sledování, historie); analýzy se dělají nad ní, ne
> nad infoSoudem. Oportunistický způsob plnění neznamená postradatelnost:
> tabulka se nikdy nemaže a řádky se svévolně neodstraňují — nabalují na
> sebe metadata (vazby jednání, oblíbené, budoucí atributy). Starší výskyty
> slova „cache“ v dokumentech se převádějí postupně. „Spisovna“ je jen
> koncepční pojem — kód i DB pojmenovávají obsah, ne kontejner: cílově
> entita `CaseFile` a tabulka `case_file` (viz CLAUDE.md, *Terminologie*).

- **Tabulka `proceeding`** (migrace 2026-07-18-02/03): ve sloupcích jen
  vyhledávací klíče identity **(soud, rejstřík, senát, číslo, ročník)**,
  zbytek v nativních JSON sloupcích per zdroj (`infosoud_json`/`infosoud_at`,
  `isir_json`/`isir_at`). Struktura JSON se nechává volná; co potřebuje UI
  dotazovat, se **projektuje do tabulek** (viz níže). Pozor: `infosoud_json`
  není čistý verbatim odpovědi — sync do něj přidává syntetický klíč
  `firstEventDetail` (detail první události, kvůli předmětu řízení; viz
  [analyza-udalosti.md](analyza-udalosti.md)).
- **Projekční tabulky `proceeding_event` / `proceeding_relation`** (+ číselník
  `relation_type` s reverzními labely pro pohled z druhé strany vazby) — staví
  je `ProceedingProjectionService` při každém syncu z raw JSON; detaily
  událostí se dočítají lazy (thin/full řádky, `detail_fetched_at`).
  Zdůvodnění návrhu: [analyza-udalosti.md](analyza-udalosti.md).
- **Tabulka spisů je nezávislá na uživatelských datech** — ukládají se
  i řízení, která nikdo nesleduje (jednorázově zobrazená). Ta se
  **neaktualizují průběžně**, drží jen poslední známý stav + per-zdroj časy.
  Stará cache (> 1 měsíc) při zobrazení = banner „vidíš starou verzi, systém
  ji neudržuje“ + tlačítko jednorázové aktualizace (5min cooldown per spis).
- **Plnění:** `bin/infosoud-fetch.php` (jeden spis přes `InfosoudClient`)
  a průběžně samotný web (tlačítko „Otevřít“ na HP, ruční refresh na detailu).
  Základ cache (~13 tis. řízení v `isir_json`) pochází z jednorázového importu
  měsíčních výpisů ISIR lustrace; importní tool byl po splnění účelu odstraněn.

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
  *Tool spisovky*): spisovna `proceeding` a evidence jednání `hearing`
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
