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
| `Codelist` | `court`, `registry`, `relation_type`, `court_prefix`, `senate_rule` | 🟡 rozpracováno | hotovo `relation_type` (2026-08-05); zbytek číselníků čeká |
| `Favorite` | `favorite`, `favorite_group` | ✅ hotovo (2026-08-05) | `Favorite` + `FavoriteGroup`; ruční řazení a transakce |
| `Hearing` | `hearing`, `hearing_room`, `hearing_observation` | 🟡 rozpracováno | hotovo `hearing_room` (2026-08-05); `hearing`/`hearing_observation` čekají (enum `court_binding`, DATE + TIME) |
| `Proceeding` | `proceeding`, `proceeding_event`, `proceeding_relation` | ⬜ | největší; cílově entita `CaseFile` |

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

#### Námět pro balíček hydrator

Aplikace nemá jak zjistit, jestli je property částečně vyplněné entity
inicializovaná — musela by na to sáhnout reflexí, kterou balíček dělá
interně (`PropertySlot::$reflection->isInitialized()`). Hodilo by se veřejné
`Hydrator::isInitialized(Entity $entity, string $property): bool` (případně
`initializedProperties()`), aby repository mohla u patch-entity bezpečně
zjistit, co volající vyplnil, místo aby to obcházela přepsáním hodnoty.

## Plán dalších kol

Pravidla postupu (zadáno 2026-08-04): **po malých částech, každé kolo
samostatně otestovat** — `composer check` (phpstan + latte-lint + tester)
a **ověření funkčnosti v prohlížeči** u všeho, co má UI. Každé kolo = jeden
commit (případně několik), nikdy nezačínat další doménu s rozpracovanou
předchozí.

**Doporučené pořadí** (od nejmenšího rizika k největšímu; v závorce počet
souborů, které se dotýkají repository):

1. **`Codelist` — `RelationTypeRepository`** (1 konzument) a
   **`HearingRoomRepository`** (1) — nejmenší izolované kousky, dobré na
   ověření vzoru u číselníku a u CLI zápisu.
2. ~~**`Favorite`**~~ — hotovo, viz výše.
3. **`Codelist` — `CourtRepository` (11 konzumentů)** a
   **`RegistryRepository` (5)** — nejrozšířenější, ale read-only a bez
   složitých typů; hodně mechanické práce, žádná záludnost.
4. **`Hearing`** (`HearingRepository` 3) — první doména s **enumem**
   (`court_binding` → `BackedEnum`) a s **TIME** sloupcem
   (`#[Type\Time]` nebo `DateInterval`); zároveň příležitost zavést enum
   i na straně DB (CHECK/ENUM) podle zásad.
5. **`Proceeding` → `CaseFile`** (8 + 4 + 2 konzumenty) — největší a
   nejcitlivější: projekční tabulky, raw JSON sloupce (**netypovat** —
   zůstávají snapshotem), `ProceedingProjectionService`, `SpisPresenter`.
   Dělat na několik kol (nejdřív `proceeding_relation`, pak
   `proceeding_event`, nakonec `proceeding`).
   **Až tady** se řeší přejmenování na `CaseFile` a DB vlna
   `proceeding` → `case_file` (viz CLAUDE.md, *Terminologie*).

### Na co si dát pozor

- **Raw JSON sloupce se netypují** — `infosoud_json`/`isir_json` zůstávají
  stringem v entitě (snapshot filozofie); struktura se čte projekcemi.
- **Šablony**: entita není view-model. Presentery mají dál skládat pole pro
  Latte (viz `{varType}` konvence), ne posílat entity syrové — jinak se
  vazba na DB schéma přesune do šablon.
- **PHPStan ignore `ActiveRow`** (`web/phpstan.neon`) se bude s postupem
  převodu zužovat; jeho **odstranění je výstupní kritérium** celého
  refactoringu (položka AN-2 v [tech-debt.md](tech-debt.md)).
- **Testy**: u každé domény zvážit test hydratace (fixture řádek → entita →
  zpět), zejména u `Proceeding` (položka AN-3).
- Zbytky v [tech-debt.md](tech-debt.md), které refactoring přirozeně
  uzavře: MISC-5 (`->fetch() ?: null` idiom, CRUD symetrie), ST-3 (šablona
  hrabe v raw JSON), ST-8 (`{varType}` drift).
