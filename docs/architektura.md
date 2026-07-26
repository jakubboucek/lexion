# Architektura (návrh)

Dohodnutý plán z brainstormingu (2026-07-17). Skeleton už stojí (viz CLAUDE.md);
tento dokument popisuje, **co se teprve bude stavět** — při implementaci jednotlivých
částí přesouvej hotové věci do CLAUDE.md a tady je škrtej.

## Základní rozhodnutí

- **Monolit Nette** — web UI + CLI commandy spouštěné cronem. Hosting: vlastní VPS.
- **Multi-user od začátku, minimalisticky** — účty zakládá obsluha ručně (CLI),
  žádná samoobslužná registrace. Uživatelé: „já + pár známých“.
- **Notifikace: Telegram bot** (Bot API `sendMessage` = jeden POST). Párování uživatele
  s chatem přes `/start <token>` deep-link bota. Další kanály (e-mail, ntfy) případně později.
- **Dead-man's switch je součást MVP** — když checker opakovaně selhává (změna API, výpadek),
  pošle se to Telegramem taky; jinak se o rozbití dozvíme až zmeškaným jednáním.

## Doménové moduly

Aplikace je interně dělená na doménové moduly — každý zdroj dat je samostatný modul
v `web/app/Model/<Domain>/`:

| Modul | Obsah | Stav |
|-------|-------|------|
| `Infosoud` | klient neoficiálního API (viz [infosoud-api.md](infosoud-api.md)), parser spisovky, číselník soudů | ✅ klient, parser, link builder, detail spisu; chybí monitoring |
| `Jednani` | „Informace o jednání“ (jednání po soudech/dnech, vlastní endpoint infosoudu) | budoucí |
| `Isir` | insolvenční rejstřík — má **oficiální API**, není třeba scrapovat; zatím jen import měsíčních výpisů do cache | budoucí |
| `Nss` | archivace rozsudků NS/NSS (veřejné jen 14 dní po vyhlášení) | budoucí |

Společný cyklus všech modulů: **fetch → snapshot (raw) → diff → notifikace.** Sdílená
infrastruktura (fronta, notifier, watch tabulky) je proto společná; modul dodává jen
klienta zdroje a diff logiku. Tabulka sledování dostane sloupec `source`.

Presentation vrstva zůstává dělená podle publika (public / modul `Panel`), doménové moduly
do ní přidávají presentery/šablony do příslušné zóny.

## Získávání dat: tři priority

Všechny cesty k infosoudu sdílejí **jeden globální rozpočet requestů** (token bucket
v DB/cache) a jeden HTTP klient. Priorita čerpání: realtime > prioritní joby > scan.
Circuit breaker: když infosoud opakovaně selhává, pozastavují se vrstvy odspodu
(nejdřív scan, pak joby; realtime zůstává nejdéle).

### 1. Realtime (synchronní, nejvyšší priorita)

Uživatel otevře detail spisu, který není v DB (nebo si vyžádá aktualizaci) → **okamžitý
synchronní dotaz** na infosoud, výsledek se hned zobrazí i uloží do DB (cache + snapshot).

- **Deduplikace souběhu:** dva požadavky na tentýž necachovaný spis současně = jeden
  fetch (zámek per spisovka), druhý čeká na výsledek.
- **Limity:** nepřihlášený dle IP **1 necachované hledání / min** (cachované spisy bez
  limitu); přihlášený měkčí limit. **IP za Cloudflare řešit balíčkem
  [jakubboucek/nette-http-request-strict-proxy](https://github.com/jakubboucek/nette-http-request-strict-proxy)**
  — fail-closed ověření CDN přes pre-shared key hlavičku (nastaví se v CF Transform
  Rule), nikdy důvěra podle IP (`CF-Connecting-IP` samotné jde spoofnout při obejití
  CF na origin). Per-spis cooldown ručních aktualizací (např. 1× za 5 min
  globálně) — chrání před refresh-spamem na jednom spisu.
- **Ochrany:** produkce za Cloudflare (v nouzi JS challenge), `robots.txt` zakáže
  podstránky s daty spisů.

### 2. Prioritní joby (vícekrokové, fair round-robin)

Např. hledání soudu podle SZ. Job se **nerozpadá na samostatné pod-joby** — je to jeden
záznam se stavem (payload: parametry + kandidátní soudy + kurzor). Worker provede **jeden
krok** (dotaz na 1 soud, případně malou dávku ~3), posune kurzor a **zařadí job znovu na
konec fronty**. Důsledky:

- po naplnění účelu jde job ukončit (další kroky se už nespustí),
- souběžná hledání více uživatelů se přirozeně prokládají (fair round-robin),
- job má vlastní stránku s průběžným stavem/výsledky.

Pozor: SZ **není globálně unikátní** — unikátní je až pětice (soud, senát, rejstřík,
číslo, ročník). **Empiricky ověřeno (2026-07-18):** „6 C 1/2023“ existuje současně
u OS Trutnov, ObS Praha 3/6/8/10, OS Benešov, OS Beroun a OS Blansko (nalezeno
v prvních 20 z 86 prověřených soudů). A dokonce **i v rámci jednoho soudu** má
každý senát vlastní číselnou řadu: u OS Trutnov existují odlišná řízení
„6 C 1/2023“, „7 C 1/2023“, „9 C 1/2023“ i „30 C 1/2023“ (různá data zahájení).
Důsledek: hledání soudu musí defaultně **projít všechny kandidáty** a vracet
průběžný seznam nálezů (uživatel může stopnout ručně); stop při prvním nálezu
jen jako explicitní volba.

### 3. Scan sledovaných řízení (pozadí, nejnižší priorita)

Plánovač (cron 1× za 60 min) zařadí do fronty všechna řízení, která **mají být
monitorována**: existuje aktivní sledování s `monitor = true`, uživatel není uspaný,
řízení není ukončené. Worker (cron ~1× za minutu) odebírá po dávkách, šetrně sériově
s pauzou a jitterem; snapshot + diff + enqueue notifikací.

**Jedna tabulka fronty pro vrstvy 2+3** (`job_queue`): `type`, `priority`, `payload` JSON,
`scheduled_at`, `started_at`, `finished_at`, `status`, `attempts`, `error`. Worker zamyká
claimem přes UPDATE, retry s backoffem, opakovaná selhání → dead-man's switch.
(Realtime frontou neprochází — je synchronní, jen čerpá společný rozpočet.)

Notifikace jdou přes **outbox**: diff jen zapíše notifikaci do fronty zpráv, samostatný
odesílač je doručuje (retry zdarma, kanály vyměnitelné).

## Spis jako cache + sledování jako relace

- ✅ **Tabulka `proceeding` existuje** (migrace 2026-07-18-02/03) jako **měkká cache**:
  ve sloupcích jen vyhledávací klíče identity **(soud, rejstřík, senát, číslo, ročník)**,
  zbytek v nativních JSON sloupcích per zdroj (`infosoud_json`/`infosoud_at`,
  `isir_json`/`isir_at`). Struktura JSON se nechává volná, dokud se nepozná datový
  model justice; pak se přemigruje. Plnění: `bin/isir-import-listing.php` (měsíční
  výpisy ISIR lustrace, idempotentní merge dlužníků a měsíců) a
  `bin/infosoud-fetch.php` (jeden spis přes `InfosoudClient`). K 2026-07-18 v dev DB
  ~13 tis. řízení z ISIR (5+7+9+11/2025, 1+3+5/2026) a 14 s infosoud daty.
- **Tabulka spisů je nezávislá na sledováních** — ukládají se i řízení, která nikdo
  nesleduje (jednorázově zobrazená). Ta se **neaktualizují průběžně**, drží jen poslední
  známý stav + `fetched_at`. Stará cache při zobrazení = upozornění „vidíš starou verzi,
  systém ji neudržuje“ + tlačítko jednorázové aktualizace (realtime, s limity výše).
- **Ukončená řízení** (terminální stav, např. „odškrtnutá věc“): scan se zastaví **i když
  je někdo sleduje**; aktualizace jen ruční. V přehledu zašedlé, v detailu vysvětlující
  upozornění (lhůty opravných prostředků uplynuly, věc se už nehne). Seznam terminálních
  stavů = **admin-editovatelný číselník** (nehardcodovat řetězce).
- **Uspávání uživatelů:** bez přihlášení 3 měsíce → všechna sledování uživatele se uspí
  (negenerují scan). **TODO (zatím neřešit):** kolize s Telegram-only uživateli, kteří se
  na web nepřihlašují — kliknutí na odkaz z notifikace nelze počítat jako aktivitu
  (Telegram dělá náhledy odkazů = hituje URL bez interakce uživatele). Vyřešit později
  (např. potvrzovací tlačítko přímo v botu před uspáním).
- **Sledování = relační tabulka** user ↔ spis, s flagy:
  - `monitor` — udržovat aktuální (generuje scan),
  - `notify` — posílat notifikace o změnách (později granularita: jen nařízení/zrušení
    jednání ap.).
- **Stavové ikonky spisu v UI** (✅ define `Presentation/@bookmark.latte`, zobrazují se
  před sp. zn., např. v seznamu souvisejících řízení; UI se vyhýbá technickému slovu
  „cache“). Progrese outline → filled, sada Material Symbols Light:
  - `bookmark-outline` — spis ještě není v systému načtený,
  - `bookmark-added-outline` — načtený, ale průběžně neudržovaný,
  - `bookmark-added` — udržovaný/pravidelně aktualizovaný (**zatím se negeneruje** —
    čeká na monitoring),
  - `bookmark-heart` — spis mezi oblíbenými/sledovanými uživatele (**zatím se
    negeneruje** — čeká na tabulku sledování).
  Stav „starý uzavřený spis, monitoring zastaven i přes žádost uživatele“ se na úrovni
  ikonky záměrně nerozlišuje.

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

## Tool 1: Parser spisovky → infosoud (✅ implementováno 2026-07-18)

Vstupní pole pro spisovku vloženou jako celý text + selectbox soudu s textovým
filtrováním („trut“ → Trutnov). **Znovupoužitelná komponenta** (bude i ve watch
formuláři, detailu spisu, …).

- **Parser (tokenizace, ne jeden regex):** normalizace (trim, case-insensitive, sjednocení
  mezer, ořez interpunkce na krajích), pak rozpad na runy číslic/písmen. Podporované tvary:
  klasický `24 NC 3601 / 2024` i ISIR tvar s prefixem soudu `KSPH 60INS19742/2024`
  (bez mezer). Pozor na víceslovné rejstříky („P a Nc“ — infosoud API `P A NC`).
- **Validace s nápovědou:** rejstřík se validuje proti číselníku
  ([data/rejstriky-soudu.json](data/rejstriky-soudu.json) → DB); neznámý rejstřík nabídne
  textově nejbližší existující (levenshtein). Chyby konkrétní: „není uveden rok“,
  „rejstřík ‚ACB' neexistuje, mysleli jste ‚ACK'?“, „toto nevypadá jako spisová značka“.
- **Detekce soudu ze značky (pipeline pravidel → zúžení kandidátů):**
  1. prefix soudu (ISIR kódy KSPH/MSPH/… → mapování na infosoud kódy, vlastní číselník),
  2. úroveň rejstříku (Cdo jen NS → rovnou NS; INS jen KS → nabídku omezit na KS),
  3. senátní mapování (např. „60 INS“ = KS Praha) — **admin-editovatelný číselník**,
     vědomě neúplný, skládá se postupně.
  Výstup detekce: buď konkrétní soud (předvyplnit), nebo množina kandidátů (odfiltrovat
  nabídku), nebo nic. Návrh musí být otevřený dalším pravidlům.
- **Tlačítka:** „Detail spisu“ (✅ vede na `/spis/<soud>/<slug>`), „Přejít na infoSoud“
  (✅ deep-link, formáty ověřené pro OS/KS/VS/NS — viz [infosoud-api.md](infosoud-api.md);
  bez určeného soudu chyba „zvolte soud“), „Najít příslušný soud“ (jen pro přihlášené,
  async — zatím disabled placeholder, viz níže).

## Tool: Detail spisu (✅ implementováno 2026-07-18) — pravidla načítání

- **1 zobrazení detailu = max 2 requesty na justici** (řízení + první událost
  s předmětem), a to jen když spis není v cache (nebo na ruční refresh
  s cooldownem). První událost se načítá vždy společně s přehledem řízení;
  když se systém o spisu dozví jen okrajově (výpis ISIR ap.), první událost
  se nedotahuje.
- **Související spisy se NIKDY nenačítají automaticky** — v detailu jsou jen
  odkazy; cizí spis se stáhne až při kliknutí (návštěvě jeho detailu).
- **Budoucí tool „Zobrazit související řízení“:** strom/graf navazujících
  spisovek (vazby mohou být i cyklické — počítat s grafem, ne jen stromem).
  Pokud systém nezná všechny referencované spisy, založí **asynchronní job**
  (stejný mechanismus jako hledání soudu), který je dotáhne; graf se skládá
  z cache. Při návrhu struktur vazeb s tím počítat.

## Jednání: UX nejisté vazby na spis (záměr, zadáno 2026-07-26)

Datová stránka jednání a párování je v [infojednani-api.md](infojednani-api.md); tady je
**návrh chování rozhraní**.

**Výchozí filozofie:** v DB bude **naprostá většina jednání bez `confirmed`** — ověření je
drahé na requesty a plošně se dělat nebude. `venue_guess` je proto **normální stav, ne chyba**;
rozhraní ho nesmí prezentovat jako problém ani slibovat, že spis u daného soudu existuje.
Čísla z prvního importu: 12 `confirmed` × 36 334 `venue_guess`.

**Klik na jednání = ověření.** Uživatel otevírající detail spisu z přehledu jednání sám vyvolá
přesně ten request, který jsme nechtěli dělat plošně → ověřování je **líné, on-demand a zdarma**
(platí ho uživatelský zájem). Výsledek se **musí perzistovat v obou směrech** (viz „nezahazovat
získaná data“):

- **spis u soudu síně existuje** → nastavit `proceeding_id`, povýšit vazbu;
- **spis u soudu síně neexistuje** → uložit i tuto (rovněž drahou) informaci, aby se dotaz
  neopakoval při každé další návštěvě a UI to vědělo hned. **Chybí na to stav** — dnešní
  `court_binding` má jen `venue_guess`/`confirmed`; bude potřeba přidat např. `refuted`
  (+ timestamp ověření), tedy migrace a úprava CHECK.

**Flow při nezdaru** (rozhodnuto): uživatel se **přesměruje na HP**, kde už formulář spisovky
s výběrem soudu je — netvořit druhé místo se stejným formulářem. Prakticky:

- HP umí prefill přes GET `znacka` + `soud` (viz CLAUDE.md), takže stačí předat spisovku
  a soud nechat prázdný;
- doplnit **flash s vysvětlením**: z dat vyplývá, že jednání se koná u tohoto soudu, ale při
  načtení spisu se ukázalo, že spis tomuto soudu nenáleží → vyberte prosím jiný soud;
- **daň za redirect** je ztráta kontextu jednání (datum, čas, síň). Zvážit ponechání odkazu
  zpět na jednání, případně vypsat kontext do flash zprávy.

**Kolik je kandidátů na jedno jednání? Vždy právě jeden** (ověřeno 2026-07-26). Řádek `hearing`
má jediný `venue_court_kod` a infoJednání jiného kandidáta nenabízí; víc kandidátů pro jeden
řádek tedy vzniknout nemůže. (Když se stejná spisovka + datum + čas objeví u dvou soudů — 45
případů — jsou to **dva samostatné řádky**, každý se svým jedním kandidátem, a jde skoro jistě
o dvě různá řízení.) Evidovat seznam kandidátů u jednání proto **není potřeba**.

**Stav ověření drž na jednání** (rozhodnuto 2026-07-26 — dřívější návrh sdílené tabulky
`case_court_probe` byl zamítnut, důvod níže). Prakticky: `court_binding` doplnit o `refuted`
(+ čas ověření), případně ekvivalentní nullable bool. Jednání jich sdílí fakt víc
(1 491 dvojic *soud × spisovka* má dnes 2–21 jednání a podíl poroste), ale to je **levná
denormalizace** — všechna taková jednání odkazují na totéž řízení, takže se aktualizují
jedním zápisem přes `(venue_court_kod, spisovka)`.

**Proč NE sdílená tabulka negativních výsledků: 404 má v každém použití jinou trvanlivost.**

- **U existujícího jednání je vyloučení trvalé.** Pro existující jednání *musí* existovat spis —
  když ho infoSoud u soudu síně nezná, spis tomu soudu nenáleží a to se už nezmění.
- **U hledání soudu podle SZ je 404 pomíjivé.** Soudy si číselné řady vedou **nezávisle a různě
  rychle**: jeden má číslo `123` obsazené měsíce, druhý se k němu dostane až za týden. Dnešní
  „u tohoto soudu neexistuje“ tedy může za týden přestat platit, protože tam mezitím **vznikne
  nové řízení** s tímtéž číslem. Negativní výsledek hledání proto **nelze cachovat natrvalo**
  a každé hledání musí proběhnout znovu celé.

Z toho plyne i **past na projekci**: uložené vyloučení se nesmí automaticky přebít tím, že se
u soudu později objeví řízení s toutéž spisovkou — bylo by to **jiné řízení**, ne to od našeho
jednání. Proto je přesnější držet vyloučení u jednání (výrok o konkrétním jednání) než
u dvojice *soud × spisovka* (nadčasový výrok, který přestane platit).

Pro *Tool 2: Najít příslušný soud podle SZ* tedy žádná dlouhodobá cache negativ nebude.
Hypoteticky přichází v úvahu krátká TTL (~24 h), případně dlouhodobá jen pro spisovky
s **ročníkem nižším než letošní** (uzavřená řada — v datech 11 625 jednání proti 24 648
z letošního ročníku, kde se čísla stále přidělují). **Zatím se to neřeší** — feature bude
uzamčená pro striktně omezený okruh lidí, takže objem dotazů je malý.

**Pozor na záměnu příčiny 404** (viz níže): u ročníků mimo pokrytí infoSoudu neznamená 404
„spis u tohoto soudu není“, ale „infoSoud spis nezná vůbec“ — v takovém případě se vyloučení
zapisovat **nesmí**.

**Nutné rozlišení dvou důvodů nezdaru** — jinak by redirect na HP byl slepá ulička:

1. **spis u tohoto soudu není** → má smysl vybírat jiný soud (redirect na HP);
2. **infoSoud spis vůbec nepokrývá** (okresní ročník ≤ 2006, krajský ≤ 2007, NSS) → jiný soud
   nepomůže, je nutné to říct rovnou. V datech: **73 jednání s 2místným ročníkem** a dalších
   **89 s ročníkem ≤ 2007**.

**Kandidáti soudů pro předvýběr na HP** — tabulka `hearing` je pro to lepší zdroj než cache
řízení: obsahuje **28 249 distinct spisovek** oproti 13 018 v `proceeding`. Platí ale stejné
pravidlo jako u stávajícího předvýběru z cache (jediná shoda předvybere, víc shod jen vypíše
seznam, nabídka soudů se nikdy neomezuje):

| spisovka se koná u … | počet spisovek | použití |
|---|---:|---|
| 1 soudu | **23 861** (84 %) | čistý předvýběr soudu |
| 2 a více soudů | 4 388 | jen vypsat kandidáty, nepředvybírat |

**Znovu použít, nestavět nové:** ověření existence spisu před redirectem už dělá tlačítko
„Otevřít“ na HP (cache → jinak fetch, který cache rovnou naplní) — flow z jednání má jet
přes tentýž mechanismus.

## Tool 2 (záměr): Najít příslušný soud podle SZ (async, jen po přihlášení)

Když uživatel zná jen spisovku bez soudu: **asynchronní job** zkusí spisovku na všech
kandidátních soudech (kde API nevrací „nenalezeno“). Nesmí se spouštět synchronně —
šetrnost k justici (desítky dotazů, z toho většina „404“).

- Tlačítko vede na **potvrzovací formulář jobu**: srozumitelně vysvětlí, co funkce dělá,
  že výsledek bude až po několika minutách, kde ho najde (počkat na stránce jobu, nebo
  sekce „Hledání soudu podle SZ“ v menu s historií hledání a výsledky). Volitelné zúžení
  na kraj/region (rychlejší výsledek, úspora dotazů).
- **Dopad na návrh fronty:** fronta musí umět víc typů jobů než scan sledování — job
  s vlastními parametry, prioritou, per-user omezením (rate limit) a stránkou s výsledkem.
  Počítat s tím od začátku (sloupec `type` + payload JSON).

### UI výběru soudů: checkbox strom, NE selectbox (rozhodnuto 2026-07-19)

Požadavky na výběr, kde hledat: multi-select soudů; klik na záhlaví skupiny zaškrtne
podřízené; vizuální odsazení podle zanoření; **3úrovňová hierarchie** typ soudu →
oblast → okresní soudy. Oblasti (soudní kraje dle členění 1960) jsou od commitu
`9d6d4fe` **přímo v datech**: sloupec `court.region` (PHA/STC/JIC/ZPC/SCE/VYC/JIM/SEM,
NULL pro celostátní NS/NSS) + enum `Codelist\CourtRegion` s českými labely
(„západní Čechy“ ap.) — hierarchii stavět z něj, ne odvozovat přes `parent_kod`.

Zvažován Tom Select (používáme ho v parseru spisovky pro výběr JEDNOHO soudu):
multi-select s chips umí nativně (pluginy `checkbox_options`, `remove_button`),
„zaškrtnout celou skupinu“ by byla malá custom nadstavba, odsazení jde přes custom
render — ale **nativní `<optgroup>` má jen 1 úroveň** (omezení HTML), 3 úrovně by
znamenaly zploštění na složená záhlaví, nebo plně custom rendering, tedy ohýbání
komboboxu na strom.

**Rozhodnutí:** pro potvrzovací formulář jobu (místa na stránce dost, dropdown není
potřeba) se použije **checkbox strom přímo ve stránce** — daisyUI checkboxy,
odsazení podle hloubky, na uzlech „vybrat celý obvod“ s indeterminate stavem,
nahoře filtrovací pole. Přehlednější, přístupnější a jednodušší než custom Tom
Select. Tom Select zůstává tam, kde se vybírá jeden soud z mnoha. Až na funkci
dojde, začít skicou UI.

## Číselníky (admin-editovatelné) — ✅ v DB (migrace 2026-07-18-00)

Vše v DB, admin UI postupně; do té doby editace Adminerem. Seed z migrace:

- **Soudy:** infosoud kód, název, úroveň, nadřízený soud, **aliasy měst** pro fulltext,
  **ISIR prefix** (KSPH → KSSTCAB, …).
- **Rejstříky:** kód, úroveň soudu, popis, poznámka — seed z
  [data/rejstriky-soudu.json](data/rejstriky-soudu.json); párování case-insensitive.
- **Senátní mapování:** rejstřík + číslo senátu → soud(y). **Pozor: ani senáty INS
  nejsou celostátně unikátní** (ověřeno na ISIR datech — např. senát 60 INS mají
  současně KS Praha, MS Praha i pobočka Pardubice). Tabulka proto připouští více
  řádků na senát: jediný řádek = soud určen, více řádků = zúžení kandidátů.
  Seed pro INS: vytěženo z měsíčních výpisů zveřejněných spisovek ISIR
  (7 měsíců 2025–2026, ~13,8 tis. spisovek → 109 párů senát×soud, 73 senátů,
  z toho 29 víceznačných); migrace `2026-07-18-01-relax-senate-rule-seed-ins.sql`.

## Za loginem (Panel)

Sledovaná řízení, historie událostí, notifikační nastavení a dokumenty, které už
nemají být veřejné.

## Číselník rejstříků (druhů věcí)

Stát publikuje oficiální **seznam soudních rejstříků s popisy a příslušností k úrovni
soudu** (okresní/krajský/vrchní/NS/NSS) — použije se k obohacení interního číselníku
(infosoud API vrací jen holé kódy). Strojově čitelný snapshot (staženo 2026-07-17,
115 položek): [data/rejstriky-soudu.json](data/rejstriky-soudu.json), zdroj:
[msp.gov.cz — Seznam rejstříků soudů](https://msp.gov.cz/en/web/msp/statisticke-udaje-z-oblasti-justice/-/clanek/seznam-rejstriku-soudu).
Pozor na drobné rozdíly zápisu vůči infosoud API (MSP „P a Nc“ × API „P A NC“;
API kóduje vše uppercase) — párovat case-insensitively.

## Známé quirky infosoudu

Viz [infosoud-api.md](infosoud-api.md) — tam je kompletní katalog (nenalezeno = HTTP 400
s `RIZENI_0000`, tvary requestů per úroveň soudu, detail události s atributy, tři
mechanismy vazeb mezi řízeními, NS alias `NSJIMBM` + senát 0 v znackaId, zrušené
události v timeline). Neprozkoumáno zůstává: záložka **„Informace o jednání“**
(jednání po soudech/dnech — vlastní endpoint pro budoucí modul `Jednani`).

## Roadmapa (stav k 2026-07-18, pořadí dalších kroků)

Hotovo: skeleton, login-wall, deployment setup, číselníky, parser spisovky (`/spisovka`),
detail spisu (`/spis/…`), cache řízení + harvest tooly, senátní pravidla INS.

1. **Monitoring (hlavní cíl projektu):** tabulka `watch` (user ↔ proceeding, flagy
   `monitor`/`notify`) + `job_queue` (planner/worker crony) + snapshoty s diffem +
   Telegram bot (párování `/start <token>`) + outbox notifikací + dead-man's switch.
   Číselník terminálních stavů. Vše navržené výše — jen postavit.
2. **Najít příslušný soud** (async vícekrokový job nad frontou z kroku 1).
3. **Zobrazit související řízení** (graf vazeb z cache + job na dotažení chybějících).
4. **Role admin** (sloupec v `user`) + admin UI číselníků (senátní pravidla, terminální
   stavy, aliasy měst u soudů pro hledání „trut“ → KS HK / OS Trutnov).
5. **Produkční hardening:** Cloudflare + strict-proxy balíček, IP limity realtime
   vrstvy, globální token bucket + circuit breaker, deploy na lex.ion.cz.
6. Později: moduly `Jednani`, `Isir` (oficiální API), `Nss` (archiv rozsudků → S3).
