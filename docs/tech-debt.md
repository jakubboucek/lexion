# Technologický dluh — audit kódu (2026-07-27)

> Výstup auditu kódu aplikace (model, presentery, šablony, CLI, frontend,
> konfigurace). Odbavuje se postupně — hotové položky odškrtávej `[x]`
> a doplň odkaz na commit; položky, které se rozhodneme neřešit, škrtni
> s poznámkou proč. Popis architektury: [architektura.md](architektura.md),
> plány: [roadmap.md](roadmap.md).
>
> **Empirický základ v době auditu:** PHPStan level 8 — 0 chyb (48 souborů),
> nette/tester — 4/4 OK, latte-lint — 16 souborů 0 chyb, FE build aktuální
> vůči zdrojům, kolace všech 12 `CREATE TABLE` dle konvence. Dluh se
> schovává pod tímhle povrchem.

## Doporučené pořadí odbavování

1. **CH-\*** (skutečné chyby) + **SEC-1..3** — malý rozsah, největší přínos.
2. **TX-\*** (transakce/atomicita) + **CLI-1, CLI-2** (exit kódy, getopt).
3. **DUP-\*** (deduplikace doménových pravidel) — postupně, každé pravidlo
   samostatný commit.
4. **ST-1** (rozpad SpisPresenter) po krocích, **ST-2** (chybové hlášky).
5. **AN-\*** (PHPStan rozšíření, testy), **FE-\***, **MISC-\*** průběžně.

---

## CH — Skutečné chyby

- [x] **CH-1: ISIR import slučuje řízení lišící se jen senátem.**
  *Vyřešeno odstraněním (2026-07-27):* import byl jednorázová věc z počátku
  projektu; po naplnění DB už jen brzdil údržbu, `bin/isir-import-listing.php`
  smazán vč. zmínek v dokumentaci. Pozn.: dedup klíč skriptu nezahrnoval
  senát, takže v historických `isir_json` datech mohou existovat řízení se
  slitými dlužníky dvou senátů — zpětně těžko dohledatelné, bere se jako
  známá vada historického importu.

- [x] **CH-2: `hearing-bind` parsuje `JED_SIN` jinak než web → falešné
  room mismatche.** *Opraveno (2026-07-27):* fáze 2 používá sdílený
  `InfosoudHearing::fromEventDetail()` místo vlastní extrakce atributů —
  síň `-` se normalizuje na `null` a padá do fallbacku „jedna strana síň
  nemá“ shodně s webem. Ověřeno dry-runem. (Tím zmizela i kopie DUP-4
  v tomto souboru; skript má užitek — plní `proceeding_id`/`court_binding`
  a staví na něm roadmapa *UX nejisté vazby jednání*.)

- [x] **CH-3: Každá HTTP 400 z detailu události se trvale zabetonuje jako
  „detail neexistuje“.** *Opraveno (2026-07-27):* `fetchEventDetail()`
  vrací `null` (→ cache „upstream detail nemá“) jen pro `UDALOST_0000`;
  ostatní 400 (`UDALOST_0001`, validační kódy, změna kontraktu) vyhazují
  `InfosoudApiException` — presenter ukáže flash a nic neuloží, takže se
  dotaz příště zopakuje. Zvoleno místo „neukládat `detail_fetched_at`“,
  které by rozbilo legitimní cache stavu „událost detail nemá“.

- [x] **CH-4: Událost bez `event_order` je slepá ulička s klamavou
  hláškou.** *Opraveno (2026-07-27):* nový helper
  `SpisPresenter::hasUpstreamAddress()` (pokrývá i druhou slepou uličku —
  cizí událost bez známého soudu) řídí `hearingFetchable` v timeline,
  novou poctivou hlášku „K tomuto záznamu nelze podrobnosti z infoSoudu
  dohledat.“ na stránce události a skrytí odkazu „aktualizovat“.
  Pozn.: v dev datech se případ aktuálně nevyskytuje (0 z 455 událostí) —
  jde o obrannou větev pro upstream událost bez `poradi`.

- [x] **CH-5: Formuláře používaly daisyUI v4 třídy, které ve v5 neexistují.**
  *Opraveno (2026-07-27):* nebyla to nedodělaná migrace, ale chyba
  implementace (v4 idiom v projektu, který od začátku jede na v5).
  Všech 5 šablon přepsáno na dokumentovaný v5 idiom: `fieldset.fieldset`
  jako struktura pole (grid + korektní mezera label/input, která dřív
  tiše chyběla), `label` s `for=` místo vnořených labelů (mimochodem
  opravilo i nevalidní HTML z FE-4), `input`/`select` bez `-bordered`.
  Na HP mají labely `text-base` (hero formulář), utilitární stránky
  nechány na kompaktním v5 defaultu. Ověřeno v prohlížeči před/po
  (HP dark+light, login, edit oblíbeného, živá validace i Tom Select
  fungují). Zbytek sjednocení chybových hlášek zůstává v ST-6.

## SEC — Bezpečnost

- [x] **SEC-1: Signály jako GET odkazy — CSRF.** *Vyřešeno frameworkem,
  ověřeno 2026-07-27:* nette/application 3.3 vynucuje same-origin na všech
  `handle*` signálech automaticky (`AccessPolicy::applyInternalRules`)
  a nette/forms 3.2+ odmítá non-same-origin submit — obojí přes
  `Sec-Fetch-Site` s `_nss` cookie fallbackem (přístup z článku
  blog.nette.org/cs/csrf-konecne-resi-prohlizec). Empiricky ověřeno:
  cross-site i holý curl na `?do=refresh` skončí redirectem bez provedení
  (DB nezměněna), same-origin formulář + destruktivní signál fungují.
  Zdokumentováno v CLAUDE.md (sekce *CSRF ochrana*) — nepřidávat tokeny.
  Dovětek: Sec-Fetch větev je až v application 3.3 / forms 3.3 / http 3.4
  (starší řady jely jen na `_nss` cookie) — composer constrainty zvednuty
  na tato minima, aby čistá instalace nemohla resolvnout starší verze.

- [x] **SEC-2: `handleFetchEvent` bez cooldownu.** *Uzavřeno bez změny
  kódu (2026-07-27):* hrot problému (prefetchery/crawlery spouštějící
  fetch přes GET) zmizel se SEC-1 — non-browser klienti bez `_nss` cookie
  signál nespustí. Zbývá jen vědomé opakované klikání přihlášeného
  člověka po selhání upstreamu, což je legitimní retry; plošné limity
  vyřeší token bucket z roadmapy (produkční hardening).

- [ ] **SEC-3: Authenticator umožňuje enumeraci účtů.** *Odloženo
  (rozhodnutí 2026-07-27):* v této fázi se neřeší; časem se zváží
  fail-to-ban či podobný mechanismus. Původní nález:
  `web/app/Core/Authenticator.php:29,36` — „Neznámý e-mail.“ vs. „Chybné
  heslo.“ + early-return bez ověření hashe (textový i časový kanál),
  hláška jde do UI.

- [x] **SEC-4: Adminer v devstacku poslouchal na všech rozhraních.**
  *Opraveno (2026-07-27):* `127.0.0.1:8088:8080` v docker-compose.yml,
  kontejner recreatnut a binding ověřen (`docker compose port adminer`).

- [~] **SEC-5: Heslo v argv u `create-user.php`.** *Neřeší se (rozhodnutí
  2026-07-27)* — vědomá volba, dev-only tool.

- [x] **SEC-6: Název sloupce skládaný ze vstupního stringu.** *Opraveno
  (2026-07-27):* `countWithSource()`/`lastFetchedAt()` používají
  identifier placeholder `?name` (`->where('?name IS NOT NULL',
  "{$source}_json")`, `MAX(?name)`). Ověřeno na `/stats` (počty i časy
  sedí). Enum `DataSource` pro magické stringy zůstává v DUP-8.

## TX — Transakce, atomicita, konzistence dat

- [ ] **TX-1: Zápis raw JSON a přestavba projekce nejsou v jedné
  transakci.** `ProceedingSyncService.php:89–115` — insert/update
  `infosoud_json` proběhne mimo transakci, `projectInfosoud()` si otevírá
  vlastní (`ProceedingProjectionService.php:52`). Pád mezi nimi → nový
  JSON + stará projekce, a **nic to nedožene** (příští refresh vidí JSON
  jako aktuální). *Fix:* obalit obojí jednou transakcí v sync službě.

- [ ] **TX-2: Mutace oblíbených bez transakce + race na `nextPosition()`.**
  `FavoriteRepository.php:68–116`, `FavoriteGroupRepository.php:59–82`,
  `DashboardPresenter::handleRemoveGroup()` (řetězí `ungroupAll` +
  delete + renumber). Každá mutace = N samostatných UPDATE; přerušení
  nechá duplicitní/děravé pozice, souběžné `add()` dvou tabů kolidují
  (read-then-write bez zámku, DB unikát na pozici není). *Fix:*
  `Explorer::transaction()` kolem mutačních metod; zvážit unikát
  `(user_id, group_id, position)` až po stabilizaci.

- [ ] **TX-3: Unikát `hearing_observation` s nullable `room` neruší
  duplicity.** `migrations/structures/2026-07-26-00-create-hearing-tables.sql:86`
  — NULLy jsou v MariaDB unikátu navzájem různé; `INSERT IGNORE`
  (`bin/infojednani-import.php:264`) u pozorování bez síně duplicitu
  nezachytí. Deklarovaná idempotence importu u `room IS NULL` neplatí —
  a pro budoucí `source='infosoud'` (síň často neznámá) to bude běžný
  případ. *Fix:* sentinel `''` místo NULL, nebo generovaný sloupec
  v unikátu (vzor `dst_court_key`). Vyžaduje migraci + úpravu importu.

- [ ] **TX-4: Scanner nezapisuje atomicky → resume věří oříznutým
  souborům.** `bin/infojednani-scan.php:269` (`file_put_contents` přímo
  do cílového souboru; resume na ř. 218 testuje jen `is_file`). Ctrl-C/
  `ENOSPC` uprostřed zápisu → poškozená buňka se navždy přeskakuje jako
  hotová; import ji zahodí do `bad` bez identifikace. Totéž
  `_codelist.json` (ř. 166). *Fix:* zápis do `.tmp` + `rename()`;
  volitelně validace JSON při resume.

- [ ] **TX-5: `hearing-bind` bez transakce + dry-run podhodnocuje
  `relinked`.** UPDATE po jednom řádku (ř. 66–70, 163–165) — přerušení
  nechá půlku potvrzenou; a v dry-runu fáze 1 nezapíše `proceeding_id`,
  takže fáze 2 nenapočítá `relinked` — ostrý běh reportuje jiná čísla
  než náhled. *Fix:* transakce per fáze; v dry-runu simulovat fázi 1
  in-memory.

## DUP — Duplicity doménových pravidel

- [ ] **DUP-1: Predikát „je to vlastní událost spisu?“ 2×.**
  `ProceedingSyncService.php:133–138` vs.
  `ProceedingProjectionService.php:214–220` — pětinásobné porovnání
  (resolveKod / senát-s-výjimkou-0 / druhVeci / bcVec /
  `CaseYear::fromUpstream`) zkopírované znak po znaku včetně komentáře.
  Oprava kvirku na jednom místě tiše rozejde párování. *Fix:* jedna
  metoda (identity VO / `CourtCodeResolver`).

- [ ] **DUP-2: „Atributy → mapa + `-` = neuvedeno“ 4×.**
  `CaseSummaryService.php:35–40,60`, `InfosoudHearing.php:34–38,49`,
  `ProceedingProjectionService.php:301–306`,
  `SpisPresenter.php:580–585,603` — kopie se liší v trim/typu. *Fix:*
  jedna služba/statická metoda (např. v `InfosoudEventAttribute`).

- [ ] **DUP-3: Kandidáti soudu (cache → jednání) 2× v PHP + 1× v JS.**
  `HomePresenter.php:95–122` vs. `SpisovkaPresenter.php:86–116`
  (identické pravidlo vč. komentáře); klientská obdoba
  `spisovka-input.js:217–228`. Rozejdou-li se, no-JS a JS tok skončí na
  jiném soudu. *Fix:*
  `Model\Spisovka\CourtCandidateService::candidatesFor(Spisovka)`
  (vrací cached[], hearings[], soleKod), presentery jen mapují.

- [ ] **DUP-4: Extrakce `JED_*` atributů — zbývá `infosoud-fetch-hearings`.**
  `bin/infosoud-fetch-hearings.php:187–192` má poslední vlastní kopii;
  `hearing-bind.php` už používá `InfosoudHearing::fromEventDetail()`
  (opraveno s CH-2). *Fix:* převést i fetch-hearings.

- [ ] **DUP-5: HTTP klient (curl) 2× s různými zárukami.**
  `bin/infojednani-scan.php:105–136` má User-Agent (`Lexion`),
  connect-timeout, retry s backoffem; `InfosoudClient::post()`
  (ř. 150–166) nic z toho — infosoud requesty chodí anonymně a jediný
  síťový zákmit shodí zpracování. *Fix:* sdílená
  `App\Model\Http\JsonHttpClient` (timeouty, UA, retry), scanner
  i `InfosoudClient` nad ní.

- [ ] **DUP-6: Identita spisu jako pětice pozičních parametrů v 9
  signaturách.** `ProceedingRepository.php:30,48`,
  `ProceedingRelationRepository.php:32,55,73`, `HearingRepository.php:41`,
  `InfosoudClient.php:31,86`, `SpisovkaFactory.php:23` — tři inty vedle
  sebe, prohození projde tiše. `Spisovka` VO existuje, ale rozbaluje se
  po složkách hned vedle (`SpisPresenter.php:355`,
  `ProceedingSyncService.php:82`). K tomu 5 různých textových formátů
  identity klíče `a|b|c…` (`ProceedingProjectionService.php:135,147–155,243`,
  `SpisPresenter.php:512,689,722,726`) — klíč na ř. 135 navíc závisí na
  pořadí klíčů pole z `ownerRef()`. *Fix:* přijmout `Spisovka` (+ soud)
  do signatur; jedna `identityKey()` metoda.

- [ ] **DUP-7: `DateInterval → "HH:MM"` a hearing identity klíč v CLI 2×.**
  `bin/infojednani-import.php:155–161,210–213` vs.
  `bin/hearing-bind.php:101–104,121–127`. *Fix:*
  `App\Model\Hearing\HearingIdentity` (souvisí s CLI-6).

- [ ] **DUP-8: Zdroj dat a úroveň soudu jako magické stringy.**
  'infosoud'/'isir' roztroušené (StatsPresenter, ProjectionService —
  privátní konstanta); úroveň soudu `=== 'ns'` literálem
  (`SpisPresenter.php:295,460,540,717`, `HomePresenter.php:134` `'nss'`),
  ačkoli `CourtLevel` enum existuje a na jednom místě se i používá
  (`SpisPresenter.php:219`); `InfosoudEventType::label(bool $supreme)` vs.
  `InfosoudEventAttribute::label(string $courtLevel)` — dvě sesterské
  třídy, dva typy pro tutéž věc. *Fix:* `enum DataSource`; `CourtLevel`
  všude; sjednotit signatury labelů.

- [ ] **DUP-9: `refSpisovka()` v presenteru duplikuje `SpisovkaFactory`.**
  `SpisPresenter.php:804–812` vs. `SpisovkaFactory::fromProceeding()`.
  *Fix:* `SpisovkaFactory::fromEventRef(ActiveRow $event)`.

- [ ] **DUP-10: Mapování relation_type hardcoded vedle DB číselníku.**
  `ProceedingProjectionService.php:263–269,295,332` vypisuje všech 7 kódů
  ze seedu `2026-07-19-04`; FK by neznámý kód shodil transakci projekce.
  *Fix:* `enum RelationType: string` (zdroj pro match i seed kontrolu).

- [ ] **DUP-11: „NSS neumíme“ 3×, pokaždé jinak.** `HomePresenter.php:134–139`,
  `SpisovkaResolver.php:132–133`, `InfosoudLinkBuilder` (vrací null).
  *Fix:* jedno místo (SpisovkaResolver / CourtLevel::isOnInfosoud —
  pozor, ta metoda je dnes mrtvá, viz MISC-4).

## ST — Struktura prezentační vrstvy

- [ ] **ST-1: Rozpad `SpisPresenter` (824 ř., 28 metod, 14 závislostí).**
  Šest zodpovědností; postupné, samostatně nasaditelné kroky:
  1. **`CaseChipFactory`** — resolving odkazů na spisy (~200 ř.:
     `caseChip` 644–655, `resolveCaseReferences` 674–704,
     `relatedCourtIndex` 713–730, `courtNamedIn`, `refSpisovka`,
     `isCourtRegistry`); odemkne ST-4 a DUP-9.
  2. **`EventDetailService`** (Model) — `fetchEventDetail` 388–453 dnes
     v presenteru zapisuje do DB a drží druhou kopii integritního
     pravidla (vs. `ProceedingProjectionService.php:170–175`); z CLI
     (`bin/infosoud-fetch-hearings.php:193`) se týž fetch dělá potřetí.
     Řešit spolu s CH-3/CH-4.
  3. **View-factories** — `assignCaseHeader` 181–225, `buildEventsView`
     457–495, `buildRelatedView` 504–565, `buildAttributesView` 576–616,
     `buildNavazneView` 740–773 (~250 ř.); viz i ST-3 (DTO hlavičky).
  4. **`FavoriteControl`** (`UI\Control`) — hvězdička + modaly +
     signál (~50 ř. + blok `@case-header.latte:84–135`), znovupoužitelné
     na Dashboardu.
  Po rozpadu zbyde ~200 ř. Průběžně: `ownEvent()` a `isCoolingDown()`
  helpery (viz ST-7).

- [ ] **ST-2: Chybové hlášky z `$this->error('…')` se nikdy nezobrazí.**
  12 volání s pečlivým českým textem (`SpisPresenter.php:92,108,114,143,
  254,271,330,338,376`, `DashboardPresenter.php:228,238`), ale
  `Error4xx/404.latte` i `4xx.latte` vypíšou generický text. Navíc
  `SpisPresenter.php:376` posílá 503, pro kterou `Error4xxPresenter`
  šablonu nemá (spadne na 4xx.latte), zatímco `Error5xx/503.phtml`
  existuje a nikdy se nepoužije. *Fix:* vypisovat
  `$exception->getMessage()` v Error4xx šablonách (texty jsou k tomu
  psané) + dodat `Error4xx/503.latte` nebo přestat 503 posílat.

- [ ] **ST-3: Šablona hrabe přímo v raw upstream JSON.**
  `@case-header.latte:24,40–49,68–74` čte `{$infosoud['stav']}`,
  `{$isir['debtors']}` atd. z dekódovaného payloadu předaného presenterem
  (`SpisPresenter.php:186–201`) — vazba UI na tvar cizího API, změna
  klíčů = tiché zmizení řádku. Obchází `CaseSummaryService`, který na to
  vznikl. Podvarianta: `SLOZENI_SENATU` split na `|` jednou v PHP
  (`SpisPresenter.php:606–609`), podruhé v Latte
  (`@case-header.latte:55`). *Fix:* readonly DTO `CaseHeaderView` plněné
  v `CaseSummaryService`; vyřeší i trojí rozjetou `{varType}` deklaraci
  (ST-8).

- [ ] **ST-4: Dashboard obchází sdílený define spisovky.**
  `DashboardPresenter::favoriteView()` (205–221) staví vlastní tvar chipu
  (bez `linkable`/`search`), `default.latte:68–69` proto linkuje
  `:Spis:detail` ručně mimo `case-chip` define — pravidlo „kdy je
  spisovka odkaz“ na dashboardu neplatí. *Fix:* po ST-1 kroku 1 volat
  `CaseChipFactory` + define.

- [ ] **ST-5: Duplicitní šablonový scaffolding.** (a) markup řádku
  události 2× v `detail.latte` (29–63 vs. 74–93, ~20 shodných řádků);
  (b) modal-dialog 4× (`@case-header.latte:103–133`,
  `Dashboard/default.latte:39–50,87–99`); (c) `editFavorite.latte` ≈
  `editGroup.latte` z ~85 %. *Fix:* defines `event-icon`/`event-foreign`,
  `@dialog.latte` s `confirm-dialog` (udělat rovnou v POST podobě —
  SEC-1), společný edit-page wrapper.

- [ ] **ST-6: Formulářové pole 5× ve 3 nekompatibilních variantách
  chybových hlášek.** `<p n:foreach>` (editFavorite:26, editGroup:25,
  default:112) vs. `<span class="label-text-alt">` (Home/default:30) vs.
  `{inputError}` (`@case-header.latte:112`); `Sign/in.latte` chyby polí
  nevypisuje vůbec (bez JS → prázdné odeslání bez vysvětlení). *Fix:*
  `@form.latte` s `{define field}` — spojit s CH-5 (daisyUI v5 idiom).

- [ ] **ST-7: Drobné duplicity v presenterech.** Kontrola „událost patří
  řízení“ 2× (`SpisPresenter.php:112–115` vs. 141–144 → `ownEvent()`);
  cooldown tvar 2× (127–129 vs. 156–158 → `isCoolingDown()`);
  `userId()` jen v Dashboardu (244–247), jinde inline
  (`SpisPresenter.php:234,258`) → do `Panel\BasePresenter`/traity;
  `newGroupForm` ≡ `groupEditForm` vč. téže hlášky
  `UniqueConstraintViolationException` (`DashboardPresenter.php:121–143`
  vs. 168–190 → `GroupFormFactory`); pravidlo „vlastní název max 255“ 2×
  (`DashboardPresenter.php:149–151`, `SpisPresenter.php:242–244`);
  `HomePresenter::proceedingExists()` (165–189) vs.
  `SpisPresenter::fetchFromInfosoud()` (365–380) — týž vzor, jiné texty
  → `ProceedingSyncService::ensureLoaded(): enum`.

- [ ] **ST-8: `{varType}` drift.** Case-header proměnné deklarované 3×
  a pokaždé jinak (`@case-header.latte:7–18` vs. `detail.latte:1–14` vs.
  `udalost.latte:1–9`); `Dashboard/default.latte` nedeklaruje `$form`,
  ačkoli ho na ř. 112 používá (porušení konvence z CLAUDE.md). *Fix:*
  DTO z ST-3 + doplnit `$form`.

- [ ] **ST-9: Opakované Tailwind řetězce (bez komponentní vrstvy).**
  Tabulka `mt-2 overflow-x-auto` + `table border …` 8×; karta
  `card border border-base-300 bg-base-100 shadow-sm` 6×;
  `btn btn-ghost btn-xs btn-square` 8×; drobné textové utility 5×.
  *Fix:* `@ui.latte` defines (`data-table`, `panel-card`) — defines jsou
  bezpečnější než skládané třídy (Tailwind skenuje jen literály).

- [ ] **ST-10: Router — každá stránka má dvě URL.**
  `RouterFactory.php:29,33` — catch-all matchuje i `/about`,
  `/panel/dashboard/default` vedle kanonických `/o-projektu`, `/panel`;
  kanonizuje jen `/spis`. Veřejná část je indexovatelná. *Fix:*
  one-way routy pro legacy tvary, nebo canonicalize.

## AN — Statická analýza a testy

- [ ] **AN-1: `bin/` a `migrations/` nejsou pod PHPStanem.**
  `web/phpstan.neon:3–4` má `paths: [app]` — mimo analýzu ~2 500 ř.
  nejrizikovějšího kódu (raw SQL zápisy). Latentní příklad, který by
  level 8 chytil: `bin/infojednani-scan.php:250` deklaruje `$resp = null`,
  ř. 265 čte `$resp['status']` bez kontroly. *Fix:* přidat cesty
  (bin skripty možná budou chtít vlastní úroveň/ignory).

- [ ] **AN-2: Plošný ignore `ActiveRow` property access.**
  `web/phpstan.neon` vypíná kontrolu ~200 přístupů v celém projektu —
  překlep v názvu sloupce je tichý `null`. *Fix (dlouhodobý):* Row třídy
  (`nette/database` je umí) nebo array-shapes na `insert()`/`update()`
  tenkých repositories; ignore pak zúžit/odstranit.

- [ ] **AN-3: Testy pokrývají jen parsování spisovky.** Existují 4 solidní
  testy (SpisovkaParser 18, CaseYear 6, Spisovka 7,
  RegistryCodelistConsistency 3). Bez testu: **`SpisovkaSlugParser`**
  (nedůvěryhodný vstup z URL — routovací!), **`InfosoudHearing`** (čistá
  funkce, testovatelná okamžitě, viz CH-2), `SpisovkaResolver` (168 ř.
  pipeline detekce), `ProceedingProjectionService` (358 ř., jediná cesta
  dat do projekce — regrese se projeví posunutými URL událostí),
  `CaseSummaryService`, `InfosoudLinkBuilder`, `CourtCodeResolver`,
  `classifyRoom()` (nemá kam — žije ve skriptu, viz CLI-6). *Pořadí dle
  poměru riziko/cena:* InfosoudHearing + SlugParser → classifyRoom (po
  extrakci) → ProjectionService (chce testovací fixture JSON).

- [ ] **AN-4: Chybí agregovaný check a CI.** `web/composer.json` má
  `phpstan` a `tester` scripts, latte-lint není zadrátovaný nikde; žádné
  `.github/`. *Fix:* `composer check` (phpstan + tester + latte-lint);
  zvážit GitHub Actions (repo je na GitHubu).

## CLI — Robustnost nástrojů v bin/

- [ ] **CLI-1: Exit kód vždy 0.** `hearing-bind.php` (žádný exit/STDERR),
  `infojednani-import.php` (0 i při `bad > 0` či nule souborů),
  `infojednani-scan.php` (0 i s `fail > 0`), `infosoud-fetch-hearings.php`
  (0 i když selže vše). Nelze dát do cronu. *Fix:* nenulový exit při
  nenulových chybách — jednořádkové.

- [ ] **CLI-2: `getopt()` tiše ignoruje překlepy.** `hearing-bind.php:44`,
  `infojednani-import.php:41` — `--dryrun` proběhne **ostře**. *Fix:*
  validace neznámých voleb (společný parser, viz CLI-6).

- [ ] **CLI-3: Retry scanneru i na trvalé 4xx.**
  `infojednani-scan.php:252–263` — validační chyba/zmizelá síň spálí
  3 pokusy + backoff, pro každý den okna. *Fix:* retry jen na 5xx/síťové.

- [ ] **CLI-4: Court-major pořadí skenu systematicky obětuje poslední
  soudy.** `infojednani-scan.php:209–212` — při ~113h plném skenu se
  nejbližší dny posledních soudů posunou do minulosti (nenávratně).
  *Fix:* date-major iterace (nejbližší dny všem soudům první).

- [ ] **CLI-5: stdout vs. stderr, jazyk, TZ.** Chyby položek jdou na
  stdout (zmizí v `> log.txt`); scanner mluví česky, ostatní anglicky
  (konvence: kód anglicky); explicitní `Europe/Prague` má jen scanner,
  ostatní spoléhají na php.ini. *Fix:* sjednotit v rámci CLI-6.

- [ ] **CLI-6: Sdílená CLI knihovna.** Boilerplate `require autoload +
  bootConsoleApplication + getByType` 7×; option parsing 3 způsoby;
  `classifyRoom()` (7 regexů určujících `off_site` → sílu vazby) jako
  globální funkce ve skriptu bez testu; CLI zapisuje do `hearing*` raw
  SQL mimo repository (import ř. 113–264, bind ř. 68, 164), `hearing_room`
  repository nemá. *Fix:* `bin/_cli.php` (bootstrap + options + stderr/
  exit), `App\Model\Hearing\RoomClassifier` + test, zápisové metody do
  `HearingRepository`/nový `HearingRoomRepository`, `HearingIdentity`
  helper (DUP-7).

- [ ] **CLI-7: Drobné.** `infosoud-fetch.php:51` nechytá
  `InfosoudApiException` (fetch-hearings ano); `infosoud-fetch-hearings.php:131`
  hlásí „detail already cached“ i pro „upstream detail nemá“; tamtéž
  ř. 140–148 neposílá `organizaceId` (web ho posílá — u NS/aliasů jiné
  chování); `retired_at` u síní nikdo nenastavuje (import ho jen
  resetuje na null — deklarovaný životní cyklus síní neexistuje);
  Ctrl-C past (`docker compose exec` nezabije proces) platí i pro import
  a bind, kde je nebezpečnější (souběžné zápisy, in-memory cache
  `$hearingIds`) — zdokumentovat, případně lock file; kolace
  `unicode_520_ci` je case/accent-insensitive, zatímco PHP porovnává
  síně `===` — teoretická kolize `uq_hearing_room` shodí import (bez
  try/catch).

## FE — Frontend

- [ ] **FE-1: Race condition při vyprázdnění pole.**
  `spisovka-input.js:196–202` — větev `text === ''` neinkrementuje
  `requestSeq`; doběhlá stará odpověď vykreslí „Rozpoznáno“ a předvybere
  soud pro smazaný text. *Fix:* bump sekvence i při vyprázdnění (nebo
  AbortController, viz FE-2).

- [ ] **FE-2: Fetch bez `response.ok`, bez abortu, s němým catchem.**
  `spisovka-input.js:204–231` — HTTP 500 skončí výjimkou v `.json()`,
  `catch {}` nic neudělá; při výpadku endpointu zůstane viset předchozí
  (zavádějící) hlášení. *Fix:* AbortController + kontrola ok + zobrazit
  „validace nedostupná“ stav.

- [ ] **FE-3: Server nevaliduje, co JS vnucuje.** `HomePresenter.php:88`
  bere `$data->soud ?? $resolution->fixedCourtKod` a **neověří** hodnotu
  proti `candidateKods`/`fixedCourtKod` — bez JS projde kombinace, kterou
  UI nepřipouští, a uživatel dostane až „Řízení se nepodařilo najít“ po
  zbytečném dotazu na infoSoud. *Fix:* serverová kontrola v rámci DUP-3
  (`CourtCandidateService`).

- [ ] **FE-4: Přístupnost live validace.** `[data-spisovka-messages]` bez
  `role="status"`/`aria-live="polite"`; input bez
  `aria-invalid`/`aria-describedby`; do kontejneru se vkládají
  i interaktivní tlačítka („Opravit na …“) bez ohlášení. Vnořené
  `<label>` (`{label znacka /}` uvnitř `<label class="form-control">`) —
  nevalidní HTML; Tom Select `control_input` bez accessible name.
  *Fix:* aria atributy + rozplést labely (spolu s CH-5/ST-6).

- [ ] **FE-5: nette-forms chyby jako nestylovaná systémová modálka.**
  Výchozí `showFormErrors` vytvoří `<dialog class="netteFormsModal">`
  s inline stylem — prázdná spisovka při submitu vyhodí cizí těleso,
  ačkoli tentýž formulář má inline messages kontejner. *Fix:* override
  `Nette.showFormErrors` → render do daisyUI kontejnerů.

- [ ] **FE-6: Build bez pojistky.** 6 z posledních 25 commitů měnilo
  šablony bez rebuildu (zatím bez následků — žádná nová třída); první
  commit s novou Tailwind třídou bez `npm run build` nasadí produkci bez
  stylu, a `{asset? 'main.js'}` selže **tiše**. *Fix:* pre-commit hook
  nebo CI check (build + git diff --exit-code web/www/assets), případně
  aspoň poznámka do deploy skriptu.

- [ ] **FE-7: `copy-button.js` selhává potichu.** Bez
  `navigator.clipboard` (non-secure origin) klik nedělá nic;
  `writeText().then()` bez `.catch()` → unhandled rejection; dvojklik
  do 1,5 s předčasně vrátí ikonu. *Fix:* fallback/`.catch()` + vizuální
  chybový stav.

- [ ] **FE-8: Chybí lint/typecheck tooling.** Žádný ESLint
  (ani `@nette/eslint-plugin`), Prettier, stylelint; `tsconfig.json` je
  strict a zahrnuje `assets/`, ale nic ho nespouští (mrtvá konfigurace
  budící dojem kontroly). `package.json` bez `private: true`/`engines`;
  deps vs. devDeps děleno nahodile; Vite 6 je major pozadu (7+).
  *Fix:* ESLint + `typecheck` skript (nebo tsconfig smazat), úklid
  package.json.

- [ ] **FE-9: Tom Select overridy s hardcoded fallbacky barev.**
  `app.css` — ~90/110 ř. jsou TS overridy; každá daisyUI proměnná má
  natvrdo fallback (`#422ad5`, `#fff`, `#1f2937`…), který se při změně
  tématu rozejde; netematizované stíny; mrtvé selektory
  `.textarea:focus(-within)` (žádná textarea v šablonách). *Fix:*
  odstranit fallbacky (proměnné existují vždy), mrtvé selektory smazat.

- [ ] **FE-10: Drobné.** `applyCourtConstraint` dělá add/remove ~90
  options při každé odpovědi i beze změny (chybí porovnání množin);
  nekonzistentní inicializační vzor (delegace na document vs. jednorázový
  querySelectorAll) a styl (`strip-tracking-url-params.js` jediný
  s function/double-quotes); `main.js` spoléhá na Rollup interop chování
  UMD balíčku nette-forms (auto-init odstraněn bundlerem — funguje, ale
  z náhody, ne z API).

## MISC — Ostatní

- [ ] **MISC-1: N+1 dotazy.** Dashboard: na každý oblíbený spis
  `getByKod` + `subjectOf` + `statusOf` (50 oblíbených ≈ 150 dotazů;
  `DashboardPresenter.php:205–221`). Detail spisu: totéž pro související
  (`SpisPresenter.php:511–533`), `isCourtRegistry()` = dotaz na chip
  (ř. 820–823), `findBySrc/Dst` volané 2× na render (ř. 543,553 vs.
  721,725); Stats: `findByNorm` v cyklu (`StatsPresenter.php:38`).
  `CourtCodeResolver` bez cache = 2 dotazy/volání, `ownerRef()` se počítá
  2× na událost (~120 dotazů na refresh spisu s 30 událostmi, uvnitř
  transakce). *Fix:* in-memory mapa číselníku soudů (read-only, desítky
  řádků); dávkové read-model služby pro dashboard/related.

- [ ] **MISC-2: `Json::decode` bez ošetření v modelu + tiché selhání
  projekce.** `ProceedingProjectionService.php:47` (a `CaseSummaryService.php:87,103`,
  `SpisPresenter.php:187,190,299,477`) — `JsonException` propadne do 500;
  `projectInfosoud()` při ne-poli mlčky `return`uje (ř. 44–50) —
  projekce se neaktualizuje a nikdo se to nedozví. *Fix:* try/catch
  s logem (vzor `InfosoudClient`), nemlčet.

- [ ] **MISC-3: Porovnání data události raw string vs. DB-normalizovaný
  tvar.** `ProceedingProjectionService.php:170–175` — změna tvaru data
  v upstreamu (`T00:00:00`, jiný formát) by způsobila, že **každý**
  refresh vyhodnotí každou událost jako změněnou a zahodí všechna cached
  `detail_json` (tichý fetch-storm). *Fix:* normalizovat obě strany před
  porovnáním.

- [ ] **MISC-4: Mrtvý kód.** `CourtRegion` enum (celý — vypadá jako
  aktivní pravidlo, není; sloupec `region` se používá jen v datech),
  `SpisovkaResolution::isOnInfosoud()` + `CourtLevel::isOnInfosoud()`
  (reálné rozhodnutí padá jinde stringem — matoucí),
  `Spisovka::$attachedNumber` (parser plní, nikdo nečte),
  `Error5xx/503.phtml` (nikdy nevyžádaný), `UserRepository::findAll/
  getById/delete`, `HearingRepository::findAll`,
  `ProceedingRepository::findAll`, `Spisovka::slugifyRegistry` může být
  private, prázdný `LatteExtension` registrovaný v DI (bez komentáře
  o záměru). *Pozn.:* `ProceedingProjectionService::resetInfosoudEvents()`
  je **zdokumentovaný záměr** (roadmap + analyza-udalosti TODO) —
  nemazat; doplnit odkaz do docblocku, ať nepadne za oběť příštímu
  úklidu.

- [ ] **MISC-5: Nekonzistence repository vrstvy.** `findAll()` vrací
  jednou `Selection`, jindy `list<ActiveRow>`, jindy `array<string,…>`;
  `->fetch() ?: null` idiom v polovině kódu (mrtvý — Nette vrací
  `?ActiveRow`); `Selection` uniká do presenterů
  (`DashboardPresenter.php:41`, `StatsPresenter.php:28`); CRUD symetrie
  nahodilá. `strtoupper` vs. `mb_strtoupper` pro normalizaci rejstříku
  (číselník obsahuje `NSČR` — funguje jen díky CI kolaci DB,
  nezdokumentovaná závislost). *Fix:* dohodnout konvenci (vracet pole,
  Selection nepouštět ven), sjednotit mb_.

- [ ] **MISC-6: Composer/konfigurace.** `"php": ">= 8.5"` bez horní meze
  (`^8.5`); chybí `ext-mbstring`, `ext-pdo`/`ext-pdo_mysql`;
  `bootWebApplication()` ≡ `bootConsoleApplication()` (duplicitní tělo
  předstírající rozdíl); DI `search:` pattern `*Factory` zabírá
  i `Presentation/` (RouterFactory je StaticClass — zúžit `in:` nebo
  vyjmout); migrace `2026-07-26-01` dělá i ALTER hearing (název
  neodpovídá plnému obsahu — jen poznámka pro příště).

---

*Vzniklo z auditu 2026-07-27 (4 paralelní průchody: model, prezentace,
CLI+infra, frontend + empirické běhy nástrojů). Při odbavování průběžně
škrtat/odškrtávat a udržovat aktuální; až se seznam vyprázdní, soubor
smazat.*
