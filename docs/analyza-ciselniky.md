# Číselníkové paradigma — analýza a návrh cache

> Výstup brainstormingu z 5. 8. 2026: rozhodnutí o tom, které tabulky jsou
> „číselníky“ a jak se budou cachovat.
>
> **Stav: implementováno 5. 8. 2026** (`CodelistCache` + Set třídy
> + `CodelistSnapshot`, výměna vnitřků čtyř repositories). Popis výsledného
> stavu žije v [architektura.md](architektura.md) (sekce *Číselníky*
> a *Typové entity a repositories*) — **tento dokument je už jen záznam
> odůvodnění**, ne návod. Naměřený výsledek: detail spisu 93 → 29 dotazů
> (číselníky 64 → 0 při teplé cache; studený build = +4 SELECTy, jeden na
> tabulku).

## Motivace: naměřený stav

Detail spisu `/spis/os-pm/24-nc-3601-2024` provede **93 SQL příkazů**
(měřeno přes Tracy DB panel, 5. 8. 2026):

| Tabulka | Dotazů | Povaha |
|---|---|---|
| `registry` | 42 | číselník |
| `court` | 21 | číselník |
| `relation_type` | 1 | číselník |
| `proceeding` | 13 | existence-checky pro case-chipy |
| `proceeding_event` | 10 | data stránky |
| `proceeding_relation` | 4 | data stránky |

**69 % dotazů jsou číselníky a drtivá většina jsou identické duplicity**
(`displayFromNorm('NC')` desítky-krát za request). Mechanismus: každý
case-chip volá `isCourtRegistry()` + `SpisovkaFactory::fromCase()` +
`courts->getByKod()`, a Nette Explorer nic nememoizuje — každé
`table()->where()->fetch()` je nový dotaz.

Charakteristika dat potvrzuje vhodnost agresivní cache: `court` 98 řádků,
`registry` 115, `court_prefix` 16, `relation_type` 7 — celkem jednotky
až desítky kB, změny výhradně migracemi (admin UI neexistuje), přírůstky
v řádu jednotek za rok.

## Rozhodnutí o scope

**Cachují se:** `court`, `registry`, `court_prefix`, `relation_type`.

**Necachují se:**

- `senate_rule` — čte se na jediném místě (resolver při submitu formuláře),
  cache by nic nepřinesla; zůstává obyčejná repository;
- `hearing_room` — čte se výjimečně a navíc roste importem skenu (jiná
  povaha invalidace);
- budoucí `court_name_form` (slovník skloňovaných tvarů pro issue
  [#4](https://github.com/jakubboucek/lexion/issues/4)) — viz níže.

Pravidlo do budoucna: **cachovat jen to, co se čte agresivně na mnoha
místech při vykreslování** (překlady kódů, sestavování referencí). Data
čtená jedním formulářem nebo dávkou plný dotaz do DB nebolí.

## Architektura cache

### Cachují se hotové entity, ne řádky

Cache drží **serializovaný graf hotových entit** (nativní `serialize()`),
ne surové řádky. Hydrator (`jakubboucek/hydrator`) zůstává jedinou cestou
DB → entita a běží pouze při stavbě snapshotu (cache-miss); `unserialize`
je thaw už postavených objektů, ne druhá konstrukční logika.

Klíčová vlastnost nativní serializace: **sdílené reference uvnitř grafu se
zachovávají** — entita se serializuje jednou a indexové mapy na ni odkazují
referencí (`r:N;`, pár bajtů). Po thaw je `$set->byKod['OSZPCPM']` a
`$set->bySlug['os-pm']` tentýž objekt. Pomocné mapy jsou proto v cache
téměř zadarmo (objemově i časově). Právě tohle by nešlo vyjádřit přes
var_export / generovaný PHP soubor — objekty by se duplikovaly per index;
varianta „čitelný generovaný PHP soubor à la kompilovaný DI kontejner“
byla zvážena a zavržena ve prospěch `serialize()` (méně kódu, žádná
export logika per typ property).

### Snapshot: per-table Set + jedna obálka

```
CodelistSnapshot            ← jediný serializovaný kořen (konzistentní otisk všech tabulek)
├── generatedAt
├── CourtSet
│   ├── byKod:    array<string, Court>        ← primární index
│   ├── bySlug:   array<string, Court>
│   ├── byName:   array<string, Court>        ← exact lookup (ODVOL_SOUD)
│   ├── byLevel:  array<string, list<Court>>  ← findByLevels()
│   ├── byParent: array<string, list<Court>>  ← seskupení podřízených soudů
│   └── ordered:  list<Court>                 ← findAll() v pořadí z DB
├── RegistrySet    (byNorm → list per level, displayByNorm, bySlug, allNorms)
├── CourtPrefixSet (byPrefix)
└── RelationTypeSet (byCode)
```

- **Set třídy jsou hloupé serializovatelné držáky dat** — žádná logika;
  dotazová logika zůstává v repositories, které nad Setem jen sahají do map.
- **Mapy se staví při buildu snapshotu** a serializují se s ním — žádné
  per-request skládání, žádná iterace celým setem při hledání.
- **Řazení peče DB do snapshotu.** `ORDER BY level DESC, name` řadí kolací
  `utf8mb4_unicode_520_ci` (česká specifika); skládat pořadí až v PHP by
  vyžadovalo `intl` Collator a riskovalo odchylku od DB. Build dotazy proto
  zachovávají `ORDER BY` a snapshot ukládá už seřazené seznamy
  (`ordered`, pořadí uvnitř `byLevel`).
- Jeden soubor pro všechny čtyři tabulky = vzájemně konzistentní otisk
  (nikdy se nesmíchá čerstvý `court` se starou `registry`).

### Úložiště a invalidace: nette/caching

Úložištěm je **`nette/caching`** (`Cache` + `FileStorage` v `temp/cache`)
— interně dělá přesně dohodnutý mechanismus (nativní `serialize()` celého
grafu) a zadarmo přidává zamykání (safe-stream), atomický zápis
a dependency API. Vlastní freeze/thaw kód se nepíše.

Invalidace kopíruje model Nette DI kontejneru / Latte:

- **dev (debug mode):** při uložení se přibalí `Cache::Files` dependency
  na soubory entit a Set tříd — FileStorage při každém čtení ověří mtimes
  a při změně definice cache sám zahodí;
- **produkce:** ukládá se **bez** dependencies — žádné staty, cache platí
  do deploye; FTP deployment už dnes purguje `temp/cache`, takže nový kód
  a nová cache přicházejí atomicky spolu;
- **žádné TTL** — nemá opodstatnění.

Operační důsledek bez TTL: **ruční číselníková migrace na produkci bez
deploye vyžaduje ručně smazat cache** — každá číselníková migrace to musí
mít poznámkou v hlavičce (stejný zvyk jako ověřovací SELECTy u datových
migrací).

Robustnost:

- **fail-open:** jakékoli selhání thaw (nekompatibilní blob, useknutý
  soubor) = cache-miss → rebuild z DB → přepsat; selhání zápisu (read-only
  FS) není chyba — použijí se právě načtená data. Cache je optimalizace,
  nikdy závislost;
- `unserialize` s `allowed_classes` whitelistem snapshot/Set/entity tříd
  a enumů (hygiena, nic nestojí).

### Repositories

Veřejné API repositories (`getByKod`, `getBySlug`, `getByName`,
`displayFromNorm`, `findByLevels`, `findAll`, …) **zůstává** — mění se jen
vnitřek: místo bodových Explorer dotazů lazy thaw snapshotu + sahání do
map. Konzumenti změnu nepoznají (nad rámec typů entit z refactoringu).

## Kontrakt pro entity (splněn 2026-08-05)

Entity číselníků se převedly ve stávajícím stylu refactoringu (viz
[architektura.md](architektura.md), *Typové entity a repositories*);
serializovatelnost z něj plyne sama — backed enumy se serializují nativně.
Trvale platná rozhodnutí z kontraktu:

- **Enum je tady bezpečný**, na rozdíl od `relation_type.code` (pravidlo
  „string, obsluha může vložit kód mimo enum“): `court.level`
  i `registry.court_level` hlídá CHECK constraint, hodnota mimo enum je
  nemožná. `Court::$region` se odvozuje z kódu soudu (v datech ověřeno,
  že jiná hodnota neexistuje).
- **`Court::$parentKod` zůstává string kód**, ne objektová reference —
  dohledání rodiče dělá repository (mapa `byParent`) a skalární graf je
  předpoklad snapshotu.
- **`Registry` je jedna entita 1:1 s tabulkou** včetně `agenda` /
  `description` / `note` — žádná slim/full dvojice DTO (viz *Zavržené
  varianty*).
- **`SenateRule` se převedl taky** (ať `ActiveRow` zmizí z celého
  namespace), ale bez cache.
- **Konzumenti nesmí porovnávat entity přes `===` napříč requesty** (uvnitř
  jednoho requestu identita platí — celý request čte jeden thaw-nutý graf).

## Zavržené varianty (a proč)

- **Per-request memoizace v repositories** — jen náplast, řeší duplicity
  uvnitř requestu, ale ne dotazy samotné; přeskočeno rovnou na snapshot.
- **Generovaný PHP soubor (opcache)** — čitelnost à la kompilovaný DI
  kontejner, ale neumí sdílené reference objektů v mapách (duplikace per
  index) a vyžaduje export logiku per typ property. Čitelnost lze případně
  dodat debugovacím CLI výpisem snapshotu.
- **Dvouvariantní DTO (slim pro cache / full pro vyhledávání)** — úvaha
  vznikla nad issue #4 (sloupce se skloňovanými tvary). Rozpouští se
  datovým modelem: skloňované tvary jsou 0..N řádků na soud (nominativ,
  lokál s předložkou, varianty zápisu), takže patří do **samostatné child
  tabulky** (`court_name_form`), ne do sloupců `court`. Základní tabulka
  zůstává skalární → jedna entita 1:1, cachuje se celá. Per-row skalární
  metadata malého objemu (à la `registry.agenda`) klidně do základní
  tabulky a cache; 1:N nebo objemná data (texty, slovníky) do child
  tabulek mimo cache.
- **TTL pojistka** — zavrženo, viz invalidace; ruční migrace bez deploye
  se řeší disciplínou (poznámka v hlavičce migrace).

## Vazba na issue #4 (rozpoznání soudu z textu)

Rozpoznávání skloňovaných názvů soudů („Krajského soudu v Ústí nad
Labem“) se rozloží na dvě části s odlišnou morfologií:

- **úrovňová část** („Krajský soud / Krajského soudu / Krajským
  soudem…“) — uzavřená množina ~7 lemmat, kompletní pádové tvary lze
  vyjmenovat natvrdo v kódu (enum/konstanty);
- **městská část** (přívlastek neshodný: „Praha“ / „v Praze“) — patří do
  číselníkové child tabulky `court_name_form` (~98 soudů × jednotky
  tvarů), generovatelné + ručně dokorigovatelné. Naivní stemming měst je
  nespolehlivý (konsonantické alternace Praha→Praze, Plzeň→Plzni);
  plnohodnotná lemmatizace (MorphoDiTa apod.) je neúměrná závislost.

Tabulka `court_name_form` bude **samostatná repository bez cache** — čte
ji jediný formulář, plný SQL dotaz je v pořádku. Poznámky k parseru:
koncové číslo listu („42 Ca 7/2008 **- 45**“) parser už zpracovává
(`Spisovka::$attachedNumber`), ISIR prefix („KSPH …“) určí soud i bez
názvu přes `court_prefix`. Stávající exact-match
`CourtRepository::getByName` (ODVOL_SOUD) může časem přejít na tentýž
forms-mechanismus.

## Mimo scope číselníků (poznamenáno při měření)

13 dotazů na `proceeding` z titulní tabulky výše jsou existence-checky
case-chipů (`buildRelatedView` / `buildNavazneView`) — `proceeding` je
rostoucí tabulka, tam patří **batch dotaz** (jeden `WHERE … IN` přes
všechny chipy stránky), ne cache. Samostatná menší optimalizace.

## Poznámka k testům

Test `RegistryCodelistConsistency.phpt` musí číst čerstvou DB — než se
spustí nad změněným číselníkem, je potřeba smazat cache (namespace
`_App.Codelist` v `temp/cache`).
