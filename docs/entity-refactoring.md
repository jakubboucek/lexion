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
| `Codelist` | `court`, `registry`, `relation_type`, `court_prefix`, `senate_rule` | ⬜ | číselníky, hodně konzumentů, ale read-only |
| `Favorite` | `favorite`, `favorite_group` | ⬜ | uživatelská data, 4 konzumenti |
| `Hearing` | `hearing`, `hearing_room`, `hearing_observation` | ⬜ | enum `court_binding`, DATE + TIME |
| `Proceeding` | `proceeding`, `proceeding_event`, `proceeding_relation` | ⬜ | největší; cílově entita `CaseFile` |

### Hotovo: User (referenční vzor)

`web/app/Model/User/User.php` + `UserRepository.php`, konzumenti
`App\Core\Authenticator` a `bin/create-user.php`. Repository byla při té
příležitosti přesunuta z plochého `Model/` do modulu `Model/User/` (soulad
s konvencí doménových modulů).

Ověřeno: `composer check`, CLI insert i update (patch), přihlášení
v prohlížeči včetně chybných údajů a odhlášení.

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
2. **`Favorite`** (`FavoriteGroupRepository` 1, `FavoriteRepository` 3) —
   uživatelská data s mutacemi v transakcích; pozor na `Panel\Dashboard`
   a jeho šablony (view-modely se plní v presenteru, entity se do šablon
   nemají dostat syrové).
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
