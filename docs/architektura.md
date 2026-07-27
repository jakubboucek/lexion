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

## Data: evidence spisů (`proceeding`)

> **Změna paradigmatu (2026-07-27):** tato data se dřív označovala jako
> „měkká cache“ — to už neplatí a pojem cache se opouští (viz CLAUDE.md,
> *Terminologie*). Evidence spisů je základní stavební kámen klíčových
> funkcí (notifikace, sledování, historie); analýzy se dělají nad ní, ne
> nad infoSoudem. Oportunistický způsob plnění neznamená postradatelnost:
> tabulka se nikdy nemaže a řádky se svévolně neodstraňují — nabalují na
> sebe metadata (vazby jednání, oblíbené, budoucí atributy). Starší výskyty
> slova „cache“ v dokumentech se převádějí postupně.

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

Vstupní pole pro spisovku vloženou jako celý text + výběr soudu.
**Znovupoužitelná komponenta** (`Accessory\SpisovkaInputFactory` — počítá se
s ní i ve watch formuláři ap.). Tool původně žil na `/spisovka`, později se
přesunul přímo na HP; `/spisovka` dnes vrací 404 a zbyl jen JSON endpoint
`validate`. (Plánované přejmenování reliktního pojmu „spisovka“ v kódu:
viz roadmap.)

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
  *Komponenta spisovky*): cache `proceeding` a evidence jednání `hearing`
  (soud síně — jen napovídá, nikdy nepřepíše ruční volbu). Návrh je otevřený
  dalším pravidlům.
- **Výběr soudu = Tom Select combobox** s textovým filtrováním („trut“ →
  Trutnov). Tím je pokryto i původně plánované „fulltextové hledání soudů
  podle města“ ze zadání — samostatný tool ani aliasy měst v číselníku nejsou
  potřeba.
- **Tlačítka:** „Otevřít“ (ověří existenci řízení — cache, jinak fetch, který
  cache naplní — a vede na `/spis/<soud>/<slug>`), „InfoSoud“ (tupý překladač
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
