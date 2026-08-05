# Převod na typové entity — stav a plán

> Průběžný pracovní dokument refactoringu „anonymních“ datových struktur
> (`array`, `ActiveRow`) na typové entity. Designové zásady žijí v
> [roadmap.md](roadmap.md) (*Typové entity a de/hydratace*), popis hotové
> architektury v [architektura.md](architektura.md). Až bude převod hotový,
> zůstane popis stavu v architektuře a tento dokument se smaže.

## Mechanismus: jakubboucek/hydrator

Vybrán po srovnávacím POC čtyř variant (Valinor, Serde, vlastní koncept,
publikovaný balíček) — metodika, čísla a odůvodnění jsou trvale v
**[issue #10](https://github.com/jakubboucek/lexion/issues/10)**; testovací
větve a PR #6–#9 byly po vyhodnocení uzavřeny a smazány.

**Balíček je vlastní projekt autora aplikace**
([github.com/jakubboucek/hydrator](https://github.com/jakubboucek/hydrator)),
který vznikl přímo pro potřeby Lexionu. Když si refactoring vyžádá změnu
rozhraní nebo novou funkci, **je žádoucí to řešit v balíčku** — podávat tam
připomínky, náměty a issues — místo obcházení v aplikaci. Balíček je ve fázi
0.x, API se ještě může měnit.

### Zapojení

`HydratorFactory` je registrovaná v `web/config/services.neon`:

```neon
- JakubBoucek\Hydrator\HydratorFactory(
    format: 'JakubBoucek\Hydrator\Format\NetteDatabase'
    timeZone: DateTimeZone('Europe/Prague')
  )
```

- **formát `NetteDatabase`** — hodnoty jsou už otypované na obou stranách
  (`DateTimeImmutable`, `bool`, `DateInterval`), takže instance procházejí
  bez konverzí;
- **časová zóna** — každý hydratovaný datum-čas se normalizuje do zóny
  aplikace (deterministicky, nezávisle na php.ini);
- v NEONu **nefunguje `::class`** — název formátu se píše jako string.

### Konvence entit (dodržovat u každé další)

- třída implementuje prázdný marker interface `JakubBoucek\Hydrator\Entity`,
  má **typované public properties**, žádný konstruktor, žádné magic
  gettery/settery;
- **žádné atributy**, dokud si je nevynutí výjimka (atributy balíčku jsou
  escape hatch: `#[Name]`, `#[Type\Date]`, `#[Type\Time]`, `#[DateFormat]`,
  `#[Fraction]`);
- mapování jmen je konvenční: `camelCase` property ↔ `snake_case` sloupec;
- **kompozitní výstupy a stavové detekce** patří do entity jako metody nebo
  virtual get-hook properties (hydrator je v obou směrech přeskakuje);
- omezené množiny hodnot jako **`BackedEnum`** (mapuje se backing hodnotou);
- entita **neví o databázi** — hydrataci dělá repository přes
  `HydratorFactory::for()`.

### Konvence repositories

- ven jdou **jen entity** (`?Entity` / `list<Entity>`), nikdy `ActiveRow`
  ani `Selection` (pravidlo *Selection neopouští model* v CLAUDE.md platí
  dál a nově se zpřísňuje i na ActiveRow);
- **zápis bere entitu**: díky partial-update sémantice (extrahují se jen
  *inicializované* properties) je částečně vyplněná entita přirozený patch —
  viz `Authenticator` (rehash hesla) a `bin/create-user.php` (upsert);
- `fromDataSet(...)->collectList()` pro seznamy; lazy streaming
  (`fromDataSet()` bez `collect*`) je vhodný pro dávkové CLI průchody.

## Stav převodu

| Doména | Tabulka | Stav | Pozn. |
|---|---|---|---|
| `User` | `user` | ✅ hotovo (2026-08-04) | první převod; `Model/User/` |
| `Codelist` | `relation_type` | ✅ hotovo (2026-08-05) | `RelationTypeEntry` |
| `Codelist` | `court`, `registry`, `court_prefix`, `senate_rule` | ✅ hotovo (2026-08-05) | `Court`/`Registry`/`CourtPrefix`/`SenateRule` dle kontraktu v [analyza-ciselniky.md](analyza-ciselniky.md) |
| `Favorite` | `favorite`, `favorite_group` | ✅ hotovo (2026-08-05) | `Favorite` + `FavoriteGroup`; ruční řazení a transakce |
| `Hearing` | `hearing`, `hearing_room`, `hearing_observation` | ✅ hotovo (2026-08-05) | enumy `CourtBinding`/`ObservationSource`/`HearingRoomKind`, DATE + TIME |
| `Proceeding` | `proceeding`, `proceeding_event`, `proceeding_relation` | ✅ hotovo (2026-08-05) | `CaseFile` + `CaseFileEvent` + `CaseFileRelation`; tabulky se přejmenují samostatnou vlnou |

### Hotovo: User (referenční vzor)

`web/app/Model/User/User.php` + `UserRepository.php`, konzumenti
`App\Core\Authenticator` a `bin/create-user.php`. Repository byla při té
příležitosti přesunuta z plochého `Model/` do modulu `Model/User/` (soulad
s konvencí doménových modulů).

Ověřeno: `composer check`, CLI insert i update (patch), přihlášení
v prohlížeči včetně chybných údajů a odhlášení.

### Hotovo: relation_type (2026-08-05)

`Model/Codelist/RelationTypeEntry.php` + upravená `RelationTypeRepository`,
konzument `SpisPresenter::buildRelatedView()`.

Dvě věci, které se tady rozhodly a platí i pro další číselníky:

- **Kolize jmen s enumem:** entita se jmenuje `RelationTypeEntry`, protože
  holé `RelationType` už patří enumu kódů. Enum = kódová strana, entita =
  řádek číselníku; suffix `Entry` používej jen tam, kde kolize opravdu je
  (`Court`/`Registry` ji mít nebudou).
- **`code` zůstává `string`, ne enum** — číselník je editovatelný obsluhou,
  takže řádek s kódem mimo enum je legitimní stav; typování na `RelationType`
  by z něj udělalo chybu hydratace.

Mapa místo seznamu: `fromDataSet($selection, keyBy: 'code')->collectMap()`
vrací rovnou `array<string, RelationTypeEntry>` — číselník se čte jako lookup
tabulka, ne po řádcích. PHPStan pak vyžaduje `@var` u návratu (`collectMap()`
je deklarovaný jako `array<int|string, T>`) a v konzumentovi hlídej idiom
`$types[$code]->label ?? $fallback` — `(… ?? null)?->label` označí jako
zbytečný nullsafe.

Ověřeno: `composer check` + detail spisu `/spis/ks-pm/61-co-8-2025`
v prohlížeči (vypsaly se obě směrové varianty labelu, konzole čistá).

### Hotovo: hearing_room (2026-08-05)

`Model/Hearing/HearingRoom.php` + `HearingRoomKind.php` (první enum
refactoringu) + upravená `HearingRoomRepository`, konzumenti `RoomClassifier`
a `bin/infojednani-import.php` (fáze 1 číselníku síní).

Co se tu potvrdilo:

- **Enum ze CHECK constraintu:** `hearing_room.kind` má v migraci
  `CHECK (kind IN (...))`, což je přesně kandidát na `BackedEnum` —
  `HearingRoomKind`. Hydrator mapuje backing hodnotou v obou směrech, takže
  DB se neměnila. Na rozdíl od `relation_type` tady enum nevznikl jako
  aplikační doména, ale opsáním DB omezení — a proto se typovat *smí*
  (množinu drží DB, ne obsluha).
- **`RoomClassifier` vrací enum** (`array{HearingRoomKind, bool}`), takže
  klasifikace a sloupec drží stejný typ; `off_site` zůstává samostatný bool,
  kuruje se nezávisle na druhu místa.
- **Patch přes entitu i u sémantické metody:** `touchSeen()` nezůstala u pole,
  staví `HearingRoom` s vyplněnými jen `lastSeen` + `retiredAt = null`.
  Ověřeno empiricky, že inicializovaná `null` property se do UPDATE opravdu
  dostane (řádek s ručně nastaveným `retired_at` se po běhu vyčistil).
- **Kompozitní klíč patří do entity:** `HearingRoom::key()` (+ statická
  `keyOf()` pro data, která entitou ještě nejsou) nahradila ručně skládané
  `"$kod|$label"` v importu — jeden tvar klíče pro uložené i čerstvé síně.
  Pozn.: `HearingKey` je něco jiného (párovací klíče jednání, síň v nich
  záměrně není).

Ověřeno: `composer check` (test `RoomClassifier.phpt` přepsán na enum) +
běh `bin/infojednani-import.php` proti zmenšenému skenu se synteticky
přidanou síní — insert i touchSeen zapsaly správně (`kind='video'`,
`off_site=0`, `last_seen` bumpnutý u všech 1361 síní), testovací řádek
i fixture po ověření smazány, záloha tabulky v `.backups/`.

### Hotovo: Favorite (2026-08-05)

`Model/Favorite/Favorite.php` + `FavoriteGroup.php` + obě repositories,
konzumenti `Panel\DashboardPresenter`, `SpisPresenter` a jejich šablony.
První doména s mutacemi, transakcemi a UI.

Poznatky (platí i pro další domény s mutacemi):

- **Ztráta `ActiveRow::ref()` je hlavní past převodu.** Dashboard si k
  oblíbenému spisu dotahoval řízení přes `$favorite->ref('proceeding')`, což
  Nette dávkuje na jeden dotaz za celou Selection. Entita žádnou traverzaci
  nemá, takže naivní náhrada = N+1. Řešení: dávkový lookup v repository
  (`ProceedingRepository::findByIds()`, vrací mapu id → řádek) a presenter si
  ho vyzvedne jednou pro celý přehled. **Před převodem domény si vždy najdi
  `->ref(`/`->related(` v konzumentech.**
- **Zápisové metody berou entitu, ale o odvozené sloupce se stará
  repository.** `FavoriteRepository::add()` dostane entitu s identitou a
  názvem a **sama přepíše** `groupId` + `position` (nový záznam vždy patří na
  konec obecného seznamu). Vedlejší efekt je příjemný: metoda nikdy nečte
  property, kterou volající nemusel vyplnit — viz past níže.
- **Past částečných entit:** čtení neinicializované typované property je
  fatální `Error`, takže repository nesmí u „patch“ entity sáhnout na nic, co
  volající nemusel nastavit. V praxi to znamená buď hodnotu přepsat (viz
  `add()`), nebo si ji vzít z parametru, ne z entity.
- **Interní pomocníky převádět taky:** přečíslování a swapy pozic uvnitř
  repository jedou nad entitami (`bucketInOrder()`, `reposition()`), ne nad
  `ActiveRow` — jinak by v modelu zůstal magický přístup ke sloupcům a
  PHPStan ignore by se nedal zúžit.
- **Šablony dostávají view-modely, ne entity** (konvence z *Na co si dát
  pozor*): skupiny na Dashboardu jdou do Latte jako `['id', 'name']`
  (`groupView()`), detail spisu dostává rovnou dva skaláry
  `bool $isFavorite` + `?string $favoriteName` místo celého řádku. `{varType}`
  se upravily podle toho.

Ověřeno: `composer check` + kompletní průchod UI v prohlížeči pod testovacím
účtem — přidání spisu do oblíbených z detailu (hvězdička, název v `<title>`
i H1, text potvrzovacího modalu), založení skupiny včetně duplicitního názvu
(unique → chyba formuláře), přejmenování a přeřazení spisu, swap pořadí
v rámci skupiny, zrušení skupiny (spisy zpět do obecného seznamu se
zachovaným pořadím) a odebrání z oblíbených; pozice po každé mutaci ověřeny
v DB. Dev data vrácena ze zálohy v `.backups/`.

#### Námět pro balíček hydrator (podáno)

Aplikace nemá jak zjistit, jestli je property částečně vyplněné entity
inicializovaná — musela by na to sáhnout reflexí, kterou balíček dělá
interně (`PropertySlot::$reflection->isInitialized()`). Podnět na veřejné
`Hydrator::isInitialized()` / `initializedProperties()` je podaný jako
[hydrator#1](https://github.com/jakubboucek/hydrator/issues/1); evidence
dotčených míst v této aplikaci (obě `add()` metody, chybějící `save()`)
je v [lexion#11](https://github.com/jakubboucek/lexion/issues/11).

Do té doby platí: **repository si hodnotu vezme z parametru, nebo ji
přepíše — nikdy ji nečte z patch-entity.**

#### Otevřená úvaha: navázané entity místo `ref()`

Ztráta `ActiveRow::ref()` (viz výše) se dnes řeší dávkovým lookupem
v repository. Autorova úvaha, jak to udělat systémověji: postavit objekt,
který **drží živé spojení na DB / `Selection`** a při iteraci vrací
**objekt s navzájem provázanými entitami** — tedy ne pole načtené dopředu,
ale iterátor/generátor, který dotahuje související řádky až při průchodu
(stejný duch jako `EntitySet`). Zatím jen nápad, nic se podle něj
nerozhoduje; než vznikne, zůstává pravidlo „před převodem domény najdi
`->ref(`/`->related(` v konzumentech a nahraď je dávkovým dotazem“.

### Hotovo: Hearing (2026-08-05)

`Model/Hearing/Hearing.php` + `HearingObservation.php` + enumy `CourtBinding`
a `ObservationSource`, přepsaná `HearingRepository`, konzumenti
`bin/infojednani-import.php` a `bin/hearing-bind.php`. Doména bez UI, zato
s dávkovými průchody přes desítky tisíc řádků.

- **DATE a TIME jsou jediné místo, kde se sáhlo po atributech balíčku:**
  `#[Type\Date]` a `#[Type\Time]` na `DateTimeImmutable`. `#[Type\Time]` je
  podstatný — bez něj by se hodnota exportovala jako plný `Y-m-d H:i:s`
  a MySQL by ji do TIME sloupce cpal s truncation note; s ním jde do DB
  `H:i:s`. Při čtení hydrator přijme i `DateInterval` (tak Nette vrací TIME)
  a připne wall time na 0001-01-01. Tím **zmizel `HearingKey::timeFromDb()`** —
  existoval jen kvůli syrovým `DateInterval` řádkům.
- **Enum jen tam, kde množinu drží DB.** `court_binding` má CHECK od začátku
  → `CourtBinding`. `hearing_observation.source` CHECK **neměl**, takže
  k enumu `ObservationSource` patří i migrace
  `2026-08-05-00-hearing-observation-source-check.sql` (plán tuhle příležitost
  přímo předpokládal). Pořadí: migraci pustit **před** nasazením kódu.
- **Dávky přes `EntitySet`, ne `Selection`.** Repository nabízí
  `streamAll()`/`streamUnconfirmed()` (lazy `fromDataSet()` bez `collect*`),
  takže CLI tooly přestaly číst přes `$db->fetchAll('SELECT … FROM hearing')`
  a zmizel i nepoužívaný `findAll(): Selection`. Klíče si staví entita
  (`Hearing::key()`, `caseTimeKey()`, `timeLabel()`) — stejný vzor jako
  `HearingRoom::key()`.
- **Raw JSON zůstává string** (`HearingObservation::$rawJson`), generovaný
  sloupec `room_key` v entitě **nemá property** — dopočítává si ho DB.

Ověřeno: `composer check`; import proti zmenšenému skenu — dry-run nad
existujícími daty nenašel ani jedno „nové“ jednání (důkaz, že entitní
`key()` dává tytéž klíče jako původní SQL index), pak syntetické jednání
prošlo insertem (DATE `2026-07-27`, TIME `13:00:00`, boolean sloupce, default
`venue_guess`) a druhý běh s novějším `platneK` refresh větví (změna
výsledku a `cancelled`, room nepřepsán, druhá observation); `hearing-bind.php`
po umělém resetu potvrdil zpět přesně stejných 14 vazeb a po vynulování
`proceeding_id` je 5 jednání znovu spárovalo. Ověřen i webový konzument
(`/spisovka/validate` → `hearingCourts`). Data vrácena ze zálohy
v `.backups/`.

### Hotovo: proceeding_relation → CaseFileRelation (2026-08-05)

První kus největší domény. `Model/Proceeding/CaseFileRelation.php` +
přepsaná `ProceedingRelationRepository`, konzumenti
`ProceedingProjectionService` (zápis) a `SpisPresenter` (obě čtecí místa).

**Pojmenování (rozhodnutí autora 2026-08-05):** tabulky se teď
**nepřejmenovávají** — `proceeding*` zůstávají a DB vlna
`proceeding` → `case_file` proběhne samostatně až po dokončení refactoringu
kódu. **Nové objekty a reference už ale cílový název nesou**: entita je
`CaseFileRelation`, ne `ProceedingRelation`. Existující třídy
(`ProceedingRelationRepository`, `ProceedingProjectionService`, …) si starý
název nechávají do té společné vlny — repository tedy dnes legitimně vypadá
jako `ProceedingRelationRepository` vracející `CaseFileRelation`.

Drobnosti, které se tu potvrdily:

- **Generovaný sloupec nemá property.** `dst_court_key` (STORED, `IFNULL`
  nad `dst_court_kod`) v entitě není — hydratace neznámé sloupce ignoruje,
  a kdyby property existovala, extrakce by ho poslala do INSERTu a MariaDB
  by zápis odmítla.
- **`relation_type` a `source` zůstávají `string`.** První ze stejného
  důvodu jako u `RelationTypeEntry::$code` (editovatelný číselník), druhý
  proto, že enum `DataSource` popisuje **zdrojové feedy spisu** a nemá case
  `manual`, který tenhle sloupec podle schématu připouští.
- **Entita jako akumulátor:** projekce si cíle vazeb skládá do
  `CaseFileRelation` bez zdrojové strany a tu i `source` dorazí až při
  zápisu — čitelnější než původní slučování polí přes `+`.

Ověřeno: `composer check` + prohlížeč — detail `/spis/ks-pm/61-co-8-2025`
(vazba směrem src) i `/spis/os-pm/24-nc-3601-2024` (11 vazeb směrem dst
včetně reverzních labelů a dotažených předmětů), a ruční „aktualizovat“,
které projekci smazalo a znovu založilo (řádek přišel se stejným obsahem
a novým id). Záloha tabulky v `.backups/`.

### Hotovo: proceeding_event → CaseFileEvent (2026-08-05)

`Model/Proceeding/CaseFileEvent.php` + přepsaná `ProceedingEventRepository`,
konzumenti `ProceedingProjectionService`, `CaseSummaryService`,
`SpisPresenter` (+ `udalost.latte`), `SpisovkaFactory::fromEventRef()`
a `bin/infosoud-fetch-hearings.php`. Zatím největší kolo.

- **Jediný `#[Name]` v projektu:** `#[Name('proceeding_id')] public int
  $caseFileId`. Vyplývá to z rozhodnutí „nové objekty a reference už nesou
  cílový název, tabulky se přejmenují až později“ — property tedy míří na
  `case_file_id` už teď a DB vlna atribut **smaže**, ne přejmenuje property.
- **Metody repository přejmenované na doménu, ne na tabulku**
  (`findByProceeding()` → `findByCaseFile()`), třída si starý název drží.
- **Párovací klíč se přestěhoval do entity** (`CaseFileEvent::pairingKey()`).
  Předtím ho skládaly dva různé kusy kódu (příchozí data vs. uložené řádky)
  a mohly se rozejít; teď ho obě strany syncu berou ze stejné metody.
  Empiricky ověřeno: opakovaná aktualizace spisu je no-op (43 událostí,
  13 detailů, stejné id) — kdyby se klíče rozešly, projekce by řádky smazala
  a založila znovu **a zahodila stažené detaily**.
- **Změna chování u nečitelného data:** `normalizedEventDate()` dřív vracela
  neparsovatelný token verbatim (aby se porovnání zvrhlo na byte equality);
  typovaný sloupec to neunese, takže teď vrací `null` = událost bez data,
  což timeline umí zobrazit ve vlastním boxu. Upstream takový token nikdy
  neposlal.
- **Past:** `{varType}` v `udalost.latte` slibovala `$event` jako `ActiveRow`,
  ale šablona ho **opravdu používala** (datum, `cancelled`, `detail_fetched_at`).
  Odstranění proměnné z presenteru se projevilo až jako Tracy warning
  v prohlížeči — `composer check` (ani latte-lint) to nechytil. Poučení:
  **u každé převáděné šablony si vypiš skutečná použití, ne jen `{varType}`.**
  Šablona teď dostává tři skaláry (`$eventDate`, `$eventCancelled`,
  `$eventFetchedAt`) místo řádku.

Ověřeno: `composer check` + prohlížeč — timeline (55 řádků, cizí události,
jednání z detailů), stránka události s atributy, lazy dotažení detailu při
prvním zobrazení, signál „Stáhnout podrobnosti“ (odkaz po stažení zmizel),
cooldown ručního refreshe události, aktualizace spisu jako no-op nad
projekcí; a CLI `bin/infosoud-fetch-hearings.php` nad načteným spisem
(čtení, filtrování i zápis stažených detailů). Záloha tabulky v `.backups/`
(detaily stažené během testu jsou platná data, nevracely se).

### Hotovo: proceeding → CaseFile (2026-08-05)

Poslední kus domény: `Model/Proceeding/CaseFile.php` + přepsaná
`ProceedingRepository`, konzumenti `ProceedingSyncService`,
`ProceedingProjectionService`, `CaseSummaryService`, `SpisovkaFactory`,
`CourtCandidateService`, presentery `Spis`/`Panel\Dashboard`, šablony spisu
a CLI `bin/infosoud-fetch.php` + datová migrace 2026-07-19-00.

- **Raw JSON zůstává string** (`infosoudJson`/`isirJson`) — snapshot
  filozofie; strukturu čtou projekční tabulky. Přístup přes zdroj řeší
  metody entity `jsonOf(DataSource)` / `fetchedAt(DataSource)`, takže se
  nikde nesestavuje název sloupce z enumu (to zůstalo jen v repository,
  kde se staví dotaz).
- **`SpisovkaFactory::fromProceeding()` → `fromCaseFile()`** — metody
  pojmenovávej podle domény, ne podle tabulky (stejně jako
  `findByProceeding()` → `findByCaseFile()`).
- **Poslední `Selection` z modelu je pryč:** nepoužívaná
  `ProceedingRepository::findAll(): Selection` zrušena; dávková reprojekce
  v datové migraci má místo ní `streamWithSource(DataSource)` (lazy
  `EntitySet`). Tím pravidlo *Selection neopouští model* platí bez výjimky.
- **Šablony dostaly skalár** `?DateTimeImmutable $infosoudAt` místo celého
  řádku spisu — `{varType}` v `detail.latte`, `udalost.latte`
  i `@case-header.latte` upravené.

Ověřeno: `composer check`; v prohlížeči čtecí cesta (detail evidovaného
spisu, timeline, vazby), **zápisová cesta insertem nového spisu** přes web
i přes `bin/infosoud-fetch.php` (řádek + 3 události projekce), vyhledávání
z HP s ověřením existence, Panel Dashboard (oblíbené přes `findByIds()`)
a `/stats` (agregace beze změny). Konzole čistá.

### Hotovo: číselníky court/registry/court_prefix/senate_rule (2026-08-05)

Poslední kolo refactoringu, dělané podle kontraktu v
[analyza-ciselniky.md](analyza-ciselniky.md) (body 1–6). Ve třech commitech:
`CourtPrefix` + `SenateRule`, `Registry`, `Court`.

- **Enum tam, kde množinu drží DB:** `Court::$level` a `Registry::$courtLevel`
  jsou `CourtLevel` (CHECK constraint), `Court::$region` je `CourtRegion`
  (hodnota se odvozuje z kódu soudu; v datech ověřeno, že jiná neexistuje).
  Tím **zmizela všechna volání `CourtLevel::from($court->level)`** roztroušená
  po presenterech, klientovi i link builderu — level je typovaný od DB až do
  šablony.
- **`parentKod` zůstává string**, ne objektová reference — dohledání rodiče
  je věc repository a scalární graf je předpoklad pro budoucí serializovaný
  snapshot.
- **Veřejné API repositories beze změny**, jen návratové typy. Jediná výjimka
  je `CourtRepository::findByLevels()`, která vracela `Selection` — teď vrací
  `list<Court>` (jinak by entita neopustila model). `ORDER BY` v dotazech
  zůstalo: řadí kolace `utf8mb4_unicode_520_ci`, ne PHP.
- **Žádná memoizace ani optimalizace uvnitř** — bodové dotazy jsou vědomě
  přechodný stav, vnitřek se vymění za snapshot v samostatné session.
  Entity na cache nijak nezávisí.
- **Šablony spisu** dostaly `{varType App\Model\Codelist\Court $court}` místo
  `ActiveRow` (soud se do nich předává celý — je to hotový read-only objekt
  číselníku, ne řádek s DB vazbami).

Ověřeno: `composer check`; v prohlížeči detail spisu a stránka události
(hlavička, čipy, deep-link na infoSoud), select soudů na HP (98 soudů v 5
skupinách podle enumu úrovně), odmítnutí NSS značky, určení soudu podle
zkratky (`KSBR`), podle pravidla senátu (`10 INS`), chybová hláška u neznámé
zkratky, `/stats` (názvy soudů, popisy rejstříků), Panel Dashboard;
CLI `bin/infosoud-fetch.php` i `bin/infosoud-fetch-hearings.php`.

## Číselníkové paradigma (rozhodnutí 2026-08-05)

Převod číselníků `court` a `registry` byl **zastaven před začátkem**. Důvod
není typovost, ale **počet dotazů**: dnešní repositories se ptají databáze
řádek po řádku a číselníky se čtou v každém odkazu na spis.

Naměřeno 2026-08-05 (general log, jedno načtení
`/spis/os-pm/24-nc-3601-2024`, celkem 90 SELECTů):

| tabulka | dotazů | řádků v tabulce |
|---|---|---|
| `registry` | 42 | 115 |
| `court` | 21 | 98 |
| `proceeding` | 13 | ~13 tis. |
| `proceeding_event` | 11 | — |
| `proceeding_relation` | 2 | — |
| `relation_type` | 1 | 7 |

Dvě číselníkové tabulky tedy dělají **70 % dotazů stránky**, a to nad daty,
která se prakticky nemění a vejdou se do paměti celá.

Rozhodnutí padlo (5. 8. 2026, detaily v
[analyza-ciselniky.md](analyza-ciselniky.md)): číselníky se budou držet jako
**serializovaný snapshot hotových entit s indexovými mapami** přes
`nette/caching`; vnějšek repositories se nemění. Entity se proto převedly
hned (viz výše) a **cache vrstva se doplní samostatně** — entity na ní
nezávisí.

**Kandidáti na číselníkové paradigma** (malé, prakticky neměnné, čtené
z mnoha míst):

| tabulka | řádků | čtení | stav |
|---|---|---|---|
| `registry` | 115 | `SpisovkaFactory`, `SpisovkaSlugParser`, `SpisovkaResolver`, `Spis`, `Stats` | **převedeno**, cache čeká |
| `court` | 98 | 11 konzumentů (presentery, `InfosoudClient`, `InfosoudLinkBuilder`, resolvery) | **převedeno**, cache čeká |
| `senate_rule` | 109 | `SpisovkaResolver` | **převedeno**, cache se dělat nebude (čte jeden formulář) |
| `court_prefix` | 16 | `SpisovkaResolver`, `CourtCodeResolver` | **převedeno**, cache čeká |
| `relation_type` | 7 | `Spis` | **převedeno** — API je „celý číselník jedním dotazem“ (`findAll()` → mapa), takže budoucí cache je čistě vnitřní změna |
| `hearing_room` | 1 361 | jen CLI import, jedním `findAll()` | **převedeno** — není v request-path a řádky se zapisují (životní cyklus síní), takže se chová jako běžná doména, ne jako číselník |

Poučení pro budoucí paradigma: **API tvaru „vrať celý číselník“** (jako
`RelationTypeRepository::findAll()`) je přesně to, co jde beze změny
konzumentů podložit pamětí. API tvaru `getByKod()`/`displayFromNorm()`
volané v cyklu je to, co dnes generuje dotazy.

## Plán dalších kol

Pravidla postupu (zadáno 2026-08-04): **po malých částech, každé kolo
samostatně otestovat** — `composer check` (phpstan + latte-lint + tester)
a **ověření funkčnosti v prohlížeči** u všeho, co má UI. Každé kolo = jeden
commit (případně několik), nikdy nezačínat další doménu s rozpracovanou
předchozí.

**Doporučené pořadí** (od nejmenšího rizika k největšímu; v závorce počet
souborů, které se dotýkají repository):

1. ~~**`Codelist` — `RelationTypeRepository`, `HearingRoomRepository`**~~ —
   hotovo, viz výše.
2. ~~**`Favorite`**~~ — hotovo, viz výše.
3. ~~**`Codelist` — `CourtRepository`, `RegistryRepository`**~~ —
   **odloženo**, viz *Odloženo: číselníkové paradigma*. Nesahat na ně, dokud
   nebude rozhodnuté, jak se budou číselníky držet v paměti.
4. ~~**`Hearing`**~~ — hotovo, viz výše.
5. ~~**`Proceeding` → `CaseFile`**~~ — hotovo, viz výše (tři kola:
   `proceeding_relation`, `proceeding_event`, `proceeding`).
   **Přejmenování tabulek se sem neváže** (rozhodnutí 2026-08-05):
   entity dostaly cílové názvy hned (`CaseFile*`), DB vlna
   `proceeding` → `case_file` se udělá samostatně po dokončení refactoringu
   kódu (viz CLAUDE.md, *Terminologie*).

**Refactoring je hotový.** Všechny domény jsou převedené a **výstupní
kritérium splněno**: `web/phpstan.neon` už nemá ignore na magické property
`ActiveRow` a level 8 prochází (AN-2 v [tech-debt.md](tech-debt.md)
odškrtnuto). Zbylé výskyty `ActiveRow` v kódu jsou jen `assert()` u
`Selection::insert()` a `instanceof` před hydratací — tedy uvnitř
repositories, ven nevychází.

Mimo tenhle dokument zbývá: **cache vrstva číselníků**
([analyza-ciselniky.md](analyza-ciselniky.md)) a **DB vlna
`proceeding` → `case_file`**.

### Na co si dát pozor

- **Raw JSON sloupce se netypují** — `infosoud_json`/`isir_json` zůstávají
  stringem v entitě (snapshot filozofie); struktura se čte projekcemi.
- **Šablony**: entita není view-model. Presentery mají dál skládat pole pro
  Latte (viz `{varType}` konvence), ne posílat entity syrové — jinak se
  vazba na DB schéma přesune do šablon.
- ~~**PHPStan ignore `ActiveRow`**~~ — zrušen 2026-08-05, viz výše. Nový
  `ActiveRow` přístup mimo repository teď PHPStan zachytí; tak to má zůstat.
- **Testy**: u každé domény zvážit test hydratace (fixture řádek → entita →
  zpět), zejména u `Proceeding` (položka AN-3).
- Zbytky v [tech-debt.md](tech-debt.md), které refactoring přirozeně
  uzavře: MISC-5 (`->fetch() ?: null` idiom, CRUD symetrie), ST-3 (šablona
  hrabe v raw JSON), ST-8 (`{varType}` drift).
