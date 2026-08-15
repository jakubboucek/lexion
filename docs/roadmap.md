# Roadmapa a plány

> Cíle, požadavky, plány a designové úvahy pro budoucí rozvoj. Popis
> **existujícího** stavu je v [architektura.md](architektura.md) a v CLAUDE.md;
> původní zadání projektu v [zadani.md](zadani.md). Když se něco z tohoto
> dokumentu implementuje, popis stavu se přesune do architektura.md/CLAUDE.md
> a tady zůstane jen to, co se ještě nepostavilo (včetně motivací — ty se
> při přesunu nesmí ztratit).

## Pořadí dalších kroků (stav k 2026-07-27)

Hotovo (viz architektura.md): skeleton, login-wall, deployment, číselníky,
parser spisovky na HP, detail spisu + detail události (projekční tabulky),
cache řízení + harvest tooly, senátní pravidla INS, dvoumístné ročníky
(`CaseYear`), oblíbené spisy, evidence jednání z infoJednání (sken → import →
párování), veřejné statistiky `/stats`, stránka `/o-projektu`, převod modelu
na typové entity + cache číselníků.

1. **Monitoring a notifikace (hlavní cíl projektu)** — viz níže.
2. **UX nejisté vazby jednání na spis** (stav `refuted`, on-demand ověřování) —
   viz níže.
3. **Najít příslušný soud podle SZ** (Tool 2, async job nad frontou z kroku 1).
4. **Zobrazit související řízení** (graf vazeb z cache + job na dotažení).
5. **Role admin** (sloupec v `user`) + admin UI číselníků (senátní pravidla,
   terminální stavy).
6. **Produkční hardening:** Cloudflare + strict-proxy balíček, IP limity
   realtime vrstvy, globální token bucket + circuit breaker.
7. Později: samostatný modul `Isir` (oficiální API), modul `Nss` (archiv
   rozsudků → S3).

## Hlavní cíl: monitoring a notifikace

Motivace (ze zadání): oficiální infoSoud neumí upozornit na změnu — kdo chce
mít přehled, obchází spisy ručně nebo platí komerční službu. Hlavní potřeba je
dozvědět se o nových událostech, **zejména o nařízených jednáních**.

- **Sledování = relační tabulka `watch`** user ↔ proceeding, s flagy:
  - `monitor` — udržovat aktuální (generuje scan),
  - `notify` — posílat notifikace o změnách (později granularita: jen
    nařízení/zrušení jednání ap.).
- **Vztah k oblíbeným spisům:** původní myšlenka byla jen evidence sledovaných
  spisů; praxe ale ukázala dřívější potřebu — mít aktuálně zajímavé spisy
  dostupné na kliknutí jako vizuální přehled. Proto vznikly jako první
  **oblíbené** (`favorite`, rychlá realizace) a používáním se ladí, jak je
  optimalizovat. **Zadávání spisů ke sledování bude vycházet z přehledu
  oblíbených** — watch není konkurent favorites, ale nadstavba.
- **Notifikace: Telegram bot** (Bot API `sendMessage` = jeden POST). Párování
  uživatele s chatem přes `/start <token>` deep-link bota. Další kanály
  (e-mail, ntfy) případně později.
- **Notifikace jdou přes outbox:** diff jen zapíše zprávu do fronty, samostatný
  odesílač doručuje (retry zdarma, kanály vyměnitelné).
- **Dead-man's switch je součást MVP** — když checker opakovaně selhává (změna
  API, výpadek), pošle se to Telegramem taky; jinak se o rozbití dozvíme až
  zmeškaným jednáním.
- **Snapshoty + diff:** při každé kontrole se ukládá surová JSON odpověď +
  normalizované události; diff se počítá proti poslednímu snapshotu. Raw data
  = pojistka proti změně neoficiálního API (zpětné přepočítání historie).
- **Ukončená řízení** (terminální stav, např. „odškrtnutá věc“): scan se
  zastaví **i když je někdo sleduje**; aktualizace jen ruční. V přehledu
  zašedlé, v detailu vysvětlující upozornění (lhůty opravných prostředků
  uplynuly, věc se už nehne). Seznam terminálních stavů = **admin-editovatelný
  číselník** (nehardcodovat řetězce).
- **Uspávání uživatelů:** bez přihlášení 3 měsíce → všechna sledování uživatele
  se uspí (negenerují scan). **TODO (zatím neřešit):** kolize s Telegram-only
  uživateli, kteří se na web nepřihlašují — kliknutí na odkaz z notifikace
  nelze počítat jako aktivitu (Telegram dělá náhledy odkazů = hituje URL bez
  interakce uživatele). Vyřešit později (např. potvrzovací tlačítko přímo
  v botu před uspáním).
- **Cyklus modulů:** fetch → snapshot (raw) → diff → notifikace. Sdílená
  infrastruktura (fronta, notifier, watch) je společná; modul dodává jen
  klienta zdroje a diff logiku. Tabulka sledování dostane sloupec `source`.

## Infrastruktura získávání dat: tři priority (návrh)

Všechny cesty k infosoudu budou sdílet **jeden globální rozpočet requestů**
(token bucket v DB/cache) a jeden HTTP klient. Priorita čerpání: realtime >
prioritní joby > scan. Circuit breaker: když infosoud opakovaně selhává,
pozastavují se vrstvy odspodu (nejdřív scan, pak joby; realtime zůstává
nejdéle).

### 1. Realtime (synchronní, nejvyšší priorita) — ✅ základ existuje

Uživatel otevře detail spisu, který není v DB (nebo si vyžádá aktualizaci) →
okamžitý synchronní dotaz, výsledek se hned zobrazí i uloží (dnes hotové:
cache-first + ruční refresh s 5min cooldownem). Zbývá:

- **Deduplikace souběhu:** dva požadavky na tentýž necachovaný spis současně
  = jeden fetch (zámek per spisovka), druhý čeká na výsledek.
- **Limity:** nepřihlášený dle IP **1 necachované hledání / min** (cachované
  spisy bez limitu); přihlášený měkčí limit. **IP za Cloudflare řešit balíčkem
  [jakubboucek/nette-http-request-strict-proxy](https://github.com/jakubboucek/nette-http-request-strict-proxy)**
  — fail-closed ověření CDN přes pre-shared key hlavičku (nastaví se v CF
  Transform Rule), nikdy důvěra podle IP (`CF-Connecting-IP` samotné jde
  spoofnout při obejití CF na origin).
- **Ochrany:** produkce za Cloudflare (v nouzi JS challenge); `robots.txt`
  disallow na `/spis/` už platí.

### 2. Prioritní joby (vícekrokové, fair round-robin)

Např. hledání soudu podle SZ. Job se **nerozpadá na samostatné pod-joby** — je
to jeden záznam se stavem (payload: parametry + kandidátní soudy + kurzor).
Worker provede **jeden krok** (dotaz na 1 soud, případně malou dávku ~3),
posune kurzor a **zařadí job znovu na konec fronty**. Důsledky:

- po naplnění účelu jde job ukončit (další kroky se už nespustí),
- souběžná hledání více uživatelů se přirozeně prokládají (fair round-robin),
- job má vlastní stránku s průběžným stavem/výsledky.

### 3. Scan sledovaných řízení (pozadí, nejnižší priorita)

Plánovač (cron 1× za 60 min) zařadí do fronty všechna řízení, která **mají být
monitorována**: existuje aktivní sledování s `monitor = true`, uživatel není
uspaný, řízení není ukončené. Worker (cron ~1× za minutu) odebírá po dávkách,
šetrně sériově s pauzou a jitterem; snapshot + diff + enqueue notifikací.

**Jedna tabulka fronty pro vrstvy 2+3** (`job_queue`): `type`, `priority`,
`payload` JSON, `scheduled_at`, `started_at`, `finished_at`, `status`,
`attempts`, `error`. Worker zamyká claimem přes UPDATE, retry s backoffem,
opakovaná selhání → dead-man's switch. (Realtime frontou neprochází — je
synchronní, jen čerpá společný rozpočet.)

## Úložiště: S3 pro objemná data

**Budoucnost: S3** (nebo kompatibilní) pro objemná data — PDF rozsudků,
archivy raw odpovědí. Při zobrazení/stažení se generuje **pre-signed URL**
(soubory nejdou přes PHP ani nejsou ve veřejném bucketu). Metadata zůstávají
v MariaDB.

## Jednání: UX nejisté vazby na spis (záměr, zadáno 2026-07-26)

Datová stránka jednání a párování je v [infojednani-api.md](infojednani-api.md)
a stav v [architektura.md](architektura.md); tady je **návrh chování
rozhraní**, zatím neimplementovaný.

**Výchozí filozofie:** v DB bude **naprostá většina jednání bez `confirmed`** —
ověření je drahé na requesty a plošně se dělat nebude. `venue_guess` je proto
**normální stav, ne chyba**; rozhraní ho nesmí prezentovat jako problém ani
slibovat, že spis u daného soudu existuje. Čísla z prvního importu:
12 `confirmed` × 36 334 `venue_guess`.

**Klik na jednání = ověření.** Uživatel otevírající detail spisu z přehledu
jednání sám vyvolá přesně ten request, který jsme nechtěli dělat plošně →
ověřování je **líné, on-demand a zdarma** (platí ho uživatelský zájem).
Výsledek se **musí perzistovat v obou směrech** (viz „nezahazovat získaná
data“):

- **spis u soudu síně existuje** → nastavit `proceeding_id`, povýšit vazbu;
- **spis u soudu síně neexistuje** → uložit i tuto (rovněž drahou) informaci,
  aby se dotaz neopakoval při každé další návštěvě a UI to vědělo hned.
  **Chybí na to stav** — dnešní `court_binding` má jen
  `venue_guess`/`confirmed`; bude potřeba přidat např. `refuted` (+ timestamp
  ověření), tedy migrace a úprava CHECK.

**Flow při nezdaru** (rozhodnuto): uživatel se **přesměruje na HP**, kde už
formulář spisovky s výběrem soudu je — netvořit druhé místo se stejným
formulářem. Prakticky:

- HP umí prefill přes GET `znacka` + `soud` (viz CLAUDE.md), takže stačí předat
  spisovku a soud nechat prázdný;
- doplnit **flash s vysvětlením**: z dat vyplývá, že jednání se koná u tohoto
  soudu, ale při načtení spisu se ukázalo, že spis tomuto soudu nenáleží →
  vyberte prosím jiný soud;
- **daň za redirect** je ztráta kontextu jednání (datum, čas, síň). Zvážit
  ponechání odkazu zpět na jednání, případně vypsat kontext do flash zprávy.

**Kolik je kandidátů na jedno jednání? Vždy právě jeden** (ověřeno 2026-07-26).
Řádek `hearing` má jediný `venue_court_kod` a infoJednání jiného kandidáta
nenabízí; víc kandidátů pro jeden řádek tedy vzniknout nemůže. (Když se stejná
spisovka + datum + čas objeví u dvou soudů — 45 případů — jsou to **dva
samostatné řádky**, každý se svým jedním kandidátem, a jde skoro jistě o dvě
různá řízení.) Evidovat seznam kandidátů u jednání proto **není potřeba**.

**Stav ověření drž na jednání** (rozhodnuto 2026-07-26 — dřívější návrh
sdílené tabulky `case_court_probe` byl zamítnut, důvod níže). Prakticky:
`court_binding` doplnit o `refuted` (+ čas ověření), případně ekvivalentní
nullable bool. Jednání jich sdílí fakt víc (1 491 dvojic *soud × spisovka* má
dnes 2–21 jednání a podíl poroste), ale to je **levná denormalizace** —
všechna taková jednání odkazují na totéž řízení, takže se aktualizují jedním
zápisem přes `(venue_court_kod, spisovka)`.

**Proč NE sdílená tabulka negativních výsledků: 404 má v každém použití jinou
trvanlivost.**

- **U existujícího jednání je vyloučení trvalé.** Pro existující jednání *musí*
  existovat spis — když ho infoSoud u soudu síně nezná, spis tomu soudu
  nenáleží a to se už nezmění.
- **U hledání soudu podle SZ je 404 pomíjivé.** Soudy si číselné řady vedou
  **nezávisle a různě rychle**: jeden má číslo `123` obsazené měsíce, druhý se
  k němu dostane až za týden. Dnešní „u tohoto soudu neexistuje“ tedy může za
  týden přestat platit, protože tam mezitím **vznikne nové řízení** s tímtéž
  číslem. Negativní výsledek hledání proto **nelze cachovat natrvalo** a každé
  hledání musí proběhnout znovu celé.

Z toho plyne i **past na projekci**: uložené vyloučení se nesmí automaticky
přebít tím, že se u soudu později objeví řízení s toutéž spisovkou — bylo by
to **jiné řízení**, ne to od našeho jednání. Proto je přesnější držet
vyloučení u jednání (výrok o konkrétním jednání) než u dvojice
*soud × spisovka* (nadčasový výrok, který přestane platit).

Pro *Tool 2* tedy žádná dlouhodobá cache negativ nebude. Hypoteticky přichází
v úvahu krátká TTL (~24 h), případně dlouhodobá jen pro spisovky s **ročníkem
nižším než letošní** (uzavřená řada — v datech 11 625 jednání proti 24 648
z letošního ročníku, kde se čísla stále přidělují). **Zatím se to neřeší** —
feature bude uzamčená pro striktně omezený okruh lidí, takže objem dotazů je
malý.

**Pozor na záměnu příčiny 404:** u ročníků mimo pokrytí infoSoudu neznamená
404 „spis u tohoto soudu není“, ale „infoSoud spis nezná vůbec“ — v takovém
případě se vyloučení zapisovat **nesmí**. Nutné rozlišení dvou důvodů nezdaru
— jinak by redirect na HP byl slepá ulička:

1. **spis u tohoto soudu není** → má smysl vybírat jiný soud (redirect na HP);
2. **infoSoud spis vůbec nepokrývá** (okresní ročník ≤ 2006, krajský ≤ 2007,
   NSS) → jiný soud nepomůže, je nutné to říct rovnou. V datech: **73 jednání
   s 2místným ročníkem** a dalších **89 s ročníkem ≤ 2007**.

**Znovu použít, nestavět nové:** ověření existence spisu před redirectem už
dělá tlačítko „Otevřít“ na HP (cache → jinak fetch, který cache rovnou
naplní) — flow z jednání má jet přes tentýž mechanismus.

## Tool 2 (záměr): Najít příslušný soud podle SZ (async, jen po přihlášení)

Když uživatel zná jen spisovku bez soudu: **asynchronní job** zkusí spisovku
na všech kandidátních soudech (kde API nevrací „nenalezeno“). Nesmí se
spouštět synchronně — šetrnost k justici (desítky dotazů, z toho většina
„404“). Na HP na něj čeká disabled tlačítko „Najít příslušný soud“.

Pozor: SZ **není globálně unikátní** — unikátní je až pětice (soud, senát,
rejstřík, číslo, ročník). **Empiricky ověřeno (2026-07-18):** „6 C 1/2023“
existuje současně u OS Trutnov, ObS Praha 3/6/8/10, OS Benešov, OS Beroun
a OS Blansko (nalezeno v prvních 20 z 86 prověřených soudů). A dokonce
**i v rámci jednoho soudu** má každý senát vlastní číselnou řadu: u OS Trutnov
existují odlišná řízení „6 C 1/2023“, „7 C 1/2023“, „9 C 1/2023“
i „30 C 1/2023“ (různá data zahájení). Důsledek: hledání musí defaultně
**projít všechny kandidáty** a vracet průběžný seznam nálezů (uživatel může
stopnout ručně); stop při prvním nálezu jen jako explicitní volba.

- Tlačítko vede na **potvrzovací formulář jobu**: srozumitelně vysvětlí, co
  funkce dělá, že výsledek bude až po několika minutách, kde ho najde (počkat
  na stránce jobu, nebo sekce „Hledání soudu podle SZ“ v menu s historií
  hledání a výsledky). Volitelné zúžení na kraj/region (rychlejší výsledek,
  úspora dotazů).
- **Dopad na návrh fronty:** fronta musí umět víc typů jobů než scan
  sledování — job s vlastními parametry, prioritou, per-user omezením (rate
  limit) a stránkou s výsledkem. Počítat s tím od začátku (sloupec `type` +
  payload JSON).

### UI výběru soudů: checkbox strom, NE selectbox (rozhodnuto 2026-07-19)

Požadavky na výběr, kde hledat: multi-select soudů; klik na záhlaví skupiny
zaškrtne podřízené; vizuální odsazení podle zanoření; **3úrovňová hierarchie**
typ soudu → oblast → okresní soudy. Oblasti (soudní kraje dle členění 1960)
jsou přímo v datech: sloupec `court.region` + enum `Codelist\CourtRegion`
(viz architektura.md) — hierarchii stavět z něj, ne odvozovat přes
`parent_kod`.

Zvažován Tom Select (používáme ho v parseru spisovky pro výběr JEDNOHO soudu):
multi-select s chips umí nativně (pluginy `checkbox_options`, `remove_button`),
„zaškrtnout celou skupinu“ by byla malá custom nadstavba, odsazení jde přes
custom render — ale **nativní `<optgroup>` má jen 1 úroveň** (omezení HTML),
3 úrovně by znamenaly zploštění na složená záhlaví, nebo plně custom
rendering, tedy ohýbání komboboxu na strom.

**Rozhodnutí:** pro potvrzovací formulář jobu (místa na stránce dost, dropdown
není potřeba) se použije **checkbox strom přímo ve stránce** — daisyUI
checkboxy, odsazení podle hloubky, na uzlech „vybrat celý obvod“
s indeterminate stavem, nahoře filtrovací pole. Přehlednější, přístupnější
a jednodušší než custom Tom Select. Tom Select zůstává tam, kde se vybírá
jeden soud z mnoha. Až na funkci dojde, začít skicou UI.

## Tool (záměr): Zobrazit související řízení

Strom/graf navazujících spisovek (vazby mohou být i cyklické — počítat
s grafem, ne jen stromem). Pokud systém nezná všechny referencované spisy,
založí **asynchronní job** (stejný mechanismus jako hledání soudu), který je
dotáhne; graf se skládá z cache. Při návrhu struktur vazeb s tím počítat.
Související spisy se ani tady **nenačítají synchronně** (viz pravidla
načítání v architektura.md).

## Menší záměry a dluhy

- **Přejmenovat pojem „spisovka“ v kódu** (záměr 2026-07-27, plošný rename
  **odložen** — rozhodnutí téhož dne): historický relikt z doby, kdy se
  struktura stránek teprve tvořila. Cílové názvy jsou rozhodnuté
  (2026-07-28) — **`CaseFile`** pro spis, **`CaseQuery`** výhradně pro
  hledání spisů (HP formulář, kladení dotazů), **`Document`** rezervováno
  pro budoucí nahrávané soubory — a **nové** třídy s nimi vznikají už teď
  (viz CLAUDE.md, *Terminologie*); existující `Spisovka*`/`Proceeding*` se
  přejmenují později najednou, spolu s DB vlnou `proceeding` → `case_file`
  při typovém refactoringu. Netýká se českého UI (tam „spisová značka“
  zůstává).
- **Seznam posledních hledání** (zadáno 2026-07-20): při nárazové práci s více
  cizími spisy (terén, mobil, testování) je otravné spisovky opakovaně
  opisovat — oblíbené slouží k dlouhodobému sledování, tohle má být rychlá
  historie naposledy otevřených spisů.
- **Sdílené atributy spisu** (`proceeding_attribute`, klíče `title`/
  `description` se speciálním významem, description jako Markdown) a **osoby
  v řízení** (`person` + M:N vazby na spisy s volným labelem vztahu); obojí
  viditelné jen přihlášeným, anonymům se má zobrazit hláška o neveřejných
  atributech.
- **Nedestruktivní obnova integrity událostí:** `resetInfosoudEvents()` je
  záměrně nezapojený — zahodit projekci nelze, párují se na ni data jednání.
  Viz TODO v [analyza-udalosti.md](analyza-udalosti.md).
- **Archiv rozsudků NS/NSS:** rozsudky jsou veřejné vždy jen 14 dní po
  vyhlášení — ukládat trvale (modul `Nss`, úložiště S3).
- **Role admin** (sloupec v `user` zatím neexistuje) + admin UI číselníků
  (senátní mapování, terminální stavy); do té doby editace Adminerem.
