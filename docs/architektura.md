# Architektura (návrh)

Dohodnutý plán z brainstormingu (2026-07-17). Skeleton už stojí (viz CLAUDE.md);
tento dokument popisuje, **co se teprve bude stavět** — při implementaci jednotlivých
částí přesouvej hotové věci do CLAUDE.md a tady je škrtej.

## Základní rozhodnutí

- **Monolit Nette** — web UI + CLI commandy spouštěné cronem. Hosting: vlastní VPS.
- **Multi-user od začátku, minimalisticky** — účty zakládá obsluha ručně (CLI),
  žádná samoobslužná registrace. Uživatelé: „já + pár známých".
- **Notifikace: Telegram bot** (Bot API `sendMessage` = jeden POST). Párování uživatele
  s chatem přes `/start <token>` deep-link bota. Další kanály (e-mail, ntfy) případně později.
- **Dead-man's switch je součást MVP** — když checker opakovaně selhává (změna API, výpadek),
  pošle se to Telegramem taky; jinak se o rozbití dozvíme až zmeškaným jednáním.

## Doménové moduly

Aplikace je interně dělená na doménové moduly — každý zdroj dat je samostatný modul
v `web/app/Model/<Domain>/`:

| Modul | Obsah | Stav |
|-------|-------|------|
| `Infosoud` | klient neoficiálního API (viz [infosoud-api.md](infosoud-api.md)), parser spisovky, číselník soudů | první na řadě |
| `Jednani` | „Informace o jednání" (jednání po soudech/dnech, vlastní endpoint infosoudu) | budoucí |
| `Isir` | insolvenční rejstřík — má **oficiální API**, není třeba scrapovat | budoucí |
| `Nss` | archivace rozsudků NS/NSS (veřejné jen 14 dní po vyhlášení) | budoucí |

Společný cyklus všech modulů: **fetch → snapshot (raw) → diff → notifikace.** Sdílená
infrastruktura (fronta, notifier, watch tabulky) je proto společná; modul dodává jen
klienta zdroje a diff logiku. Tabulka sledování dostane sloupec `source`.

Presentation vrstva zůstává dělená podle publika (public / modul `Panel`), doménové moduly
do ní přidávají presentery/šablony do příslušné zóny.

## Fronta scanů (dva crony)

Procházení sledovaných řízení je oddělené na **plánovač** a **worker**:

1. **Planner cron (1× za 60 min):** naplní frontu — zařadí všechna aktivní sledování,
   která mají být zkontrolována (per-watch konfigurovatelná frekvence).
2. **Worker cron (tikne častěji, např. 1× za minutu):** odebírá z fronty po dávkách,
   stahuje (šetrně: sériově, malá pauza + jitter mezi requesty) a předává na zpracování
   (snapshot + diff + enqueue notifikací).

Fronta = DB tabulka (`scan_queue`: watch ref, `scheduled_at`, `started_at`, `finished_at`,
`status`, `attempts`, `error`). Worker si položky zamyká (claim přes UPDATE), retry
s backoffem, opakovaná selhání → dead-man's switch.

Notifikace jdou přes **outbox**: diff jen zapíše notifikaci do fronty zpráv, samostatný
odesílač je doručuje (retry zdarma, kanály vyměnitelné).

## Snapshoty a raw data

- Při každé kontrole se ukládá **surová JSON odpověď** + normalizované události;
  diff se počítá proti poslednímu snapshotu. Raw data = pojistka proti změně
  neoficiálního API (zpětné přepočítání historie).
- **Budoucnost: S3** (nebo kompatibilní) pro objemná data — PDF rozsudků, archivy raw
  odpovědí. Při zobrazení/stažení se generuje **pre-signed URL** (soubory nejdou přes PHP
  ani nejsou ve veřejném bucketu). Metadata zůstávají v MariaDB.

## Filozofie UI: sbírka veřejných toolů

Aplikace je **public-first sbírka toolů**. Login-wall není dominanta:

- **Úvodní stránka = dashboard** s odkazy/kartami jednotlivých toolů (veřejných i těch
  za loginem). Tool vyžadující identitu přesměruje na login **až při otevření**.
- **Vždy za loginem:** systémové stránky, které nemají pro nepřihlášeného smysl
  (změna hesla, nastavení notifikací), a uživatelský obsah (sledovaná řízení).
- **Role admin:** tooly vyhrazené adminovi (správa uživatelů, provozní logy, editace
  číselníků). Vyžaduje sloupec role u uživatele (zatím neexistuje).

## Tool 1: Parser spisovky → infosoud (první tool, veřejný)

Vstupní pole pro spisovku vloženou jako celý text + selectbox soudu s textovým
filtrováním („trut" → Trutnov). **Znovupoužitelná komponenta** (bude i ve watch
formuláři, detailu spisu, …).

- **Parser (tokenizace, ne jeden regex):** normalizace (trim, case-insensitive, sjednocení
  mezer, ořez interpunkce na krajích), pak rozpad na runy číslic/písmen. Podporované tvary:
  klasický `24 NC 3601 / 2024` i ISIR tvar s prefixem soudu `KSPH 60INS19742/2024`
  (bez mezer). Pozor na víceslovné rejstříky („P a Nc" — infosoud API `P A NC`).
- **Validace s nápovědou:** rejstřík se validuje proti číselníku
  ([data/rejstriky-soudu.json](data/rejstriky-soudu.json) → DB); neznámý rejstřík nabídne
  textově nejbližší existující (levenshtein). Chyby konkrétní: „není uveden rok",
  „rejstřík ‚ACB' neexistuje, mysleli jste ‚ACK'?", „toto nevypadá jako spisová značka".
- **Detekce soudu ze značky (pipeline pravidel → zúžení kandidátů):**
  1. prefix soudu (ISIR kódy KSPH/MSPH/… → mapování na infosoud kódy, vlastní číselník),
  2. úroveň rejstříku (Cdo jen NS → rovnou NS; INS jen KS → nabídku omezit na KS),
  3. senátní mapování (např. „60 INS" = KS Praha) — **admin-editovatelný číselník**,
     vědomě neúplný, skládá se postupně.
  Výstup detekce: buď konkrétní soud (předvyplnit), nebo množina kandidátů (odfiltrovat
  nabídku), nebo nic. Návrh musí být otevřený dalším pravidlům.
- **Tlačítka:** „Detail spisu" (naše zobrazení — zatím nerealizovat), „Přejít na infoSoud"
  (deep-link, formáty ověřené pro OS/KS/NS — viz [infosoud-api.md](infosoud-api.md);
  bez určeného soudu chyba „zvolte soud"), „Najít příslušný soud" (jen pro přihlášené,
  async — viz níže).

## Tool 2 (záměr): Najít příslušný soud podle SZ (async, jen po přihlášení)

Když uživatel zná jen spisovku bez soudu: **asynchronní job** zkusí spisovku na všech
kandidátních soudech (kde API nevrací „nenalezeno"). Nesmí se spouštět synchronně —
šetrnost k justici (desítky dotazů, z toho většina „404").

- Tlačítko vede na **potvrzovací formulář jobu**: srozumitelně vysvětlí, co funkce dělá,
  že výsledek bude až po několika minutách, kde ho najde (počkat na stránce jobu, nebo
  sekce „Hledání soudu podle SZ" v menu s historií hledání a výsledky). Volitelné zúžení
  na kraj/region (rychlejší výsledek, úspora dotazů).
- **Dopad na návrh fronty:** fronta musí umět víc typů jobů než scan sledování — job
  s vlastními parametry, prioritou, per-user omezením (rate limit) a stránkou s výsledkem.
  Počítat s tím od začátku (sloupec `type` + payload JSON).

## Číselníky (admin-editovatelné)

Vše v DB, admin UI postupně; do té doby editace Adminerem. Seed z migrace:

- **Soudy:** infosoud kód, název, úroveň, nadřízený soud, **aliasy měst** pro fulltext,
  **ISIR prefix** (KSPH → KSSTCAB, …).
- **Rejstříky:** kód, úroveň soudu, popis, poznámka — seed z
  [data/rejstriky-soudu.json](data/rejstriky-soudu.json); párování case-insensitive.
- **Senátní mapování:** rejstřík + číslo senátu → soud (neúplné, skládá se postupně).

## Za loginem (Panel)

Sledovaná řízení, historie událostí, notifikační nastavení a dokumenty, které už
nemají být veřejné.

## Číselník rejstříků (druhů věcí)

Stát publikuje oficiální **seznam soudních rejstříků s popisy a příslušností k úrovni
soudu** (okresní/krajský/vrchní/NS/NSS) — použije se k obohacení interního číselníku
(infosoud API vrací jen holé kódy). Strojově čitelný snapshot (staženo 2026-07-17,
115 položek): [data/rejstriky-soudu.json](data/rejstriky-soudu.json), zdroj:
[msp.gov.cz — Seznam rejstříků soudů](https://msp.gov.cz/en/web/msp/statisticke-udaje-z-oblasti-justice/-/clanek/seznam-rejstriku-soudu).
Pozor na drobné rozdíly zápisu vůči infosoud API (MSP „P a Nc" × API „P A NC";
API kóduje vše uppercase) — párovat case-insensitively.

## Známé quirky infosoudu

Viz [infosoud-api.md](infosoud-api.md). Nejdůležitější: „nenalezeno" = HTTP 400 s kódem
`RIZENI_0000` v message (nutno odlišit od skutečné chyby); krajský soud jako 1. instance
používá jiné pole requestu než `okresniSoud` (zatím neprozkoumáno); detail jednání má
vlastní endpoint (zatím neprozkoumáno).
