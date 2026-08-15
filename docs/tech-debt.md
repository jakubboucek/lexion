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

- [x] **TX-1: Zápis raw JSON a přestavba projekce nejsou v jedné
  transakci.** *Opraveno (2026-07-27):* DB část `refreshFromInfosoud()`
  (upsert `infosoud_json` + `projectInfosoud()`) běží v jedné transakci;
  vnořená transakce projekce se připojí (nette/database drží hloubku).
  HTTP fetche zůstávají mimo transakci. Ověřeno ručním refreshem spisu.

- [x] **TX-2: Mutace oblíbených bez transakce.** *Opraveno (2026-07-27):*
  všechny vícekrokové mutace obou repositories jedou v transakci; řetězec
  `ungroupAll` + delete se přesunul z presenteru do
  `FavoriteGroupRepository::remove()`, takže sdílí jednu transakci.
  Ověřeno v prohlížeči. Otevřené zbytky: race `nextPosition()` při
  souběhu dvou tabů (duplicitní pozici zahojí příští renumber; případný
  unikát `(user_id, group_id, position)` zvážit po stabilizaci).

- [x] **TX-3: Unikát `hearing_observation` s nullable `room` neruší
  duplicity.** *Opraveno (2026-07-27):* migrace
  `2026-07-27-00-hearing-observation-room-key.sql` — generovaný sloupec
  `room_key = IFNULL(room, '')` v unikátu (vzor `dst_court_key`).
  Aplikováno na dev DB, deduplikace NULL síní ověřena syntetickým
  insertem. Pozn. z vyjasnění: `room` je nullable záměrně — infoJednání
  síň vždy má (parametr dotazu), budoucí zdroj `infosoud` mít nemusí.

- [x] **TX-4: Scanner nezapisoval atomicky.** *Opraveno (2026-07-27):*
  buňky i `_codelist.json` se zapisují přes `.tmp` + `rename()`
  (`writeAtomic()`), oříznutý soubor už nemůže projít resume kontrolou
  `is_file()`.

- [x] **TX-5: `hearing-bind` bez transakce + dry-run podhodnocoval
  `relinked`.** *Opraveno (2026-07-27):* každá fáze zapisuje v jedné
  transakci; dry-run drží výsledek fáze 1 v in-memory mapě, kterou fáze 2
  konzultuje — náhled a ostrý běh reportují stejná čísla (ověřeno
  porovnáním obou běhů).

## DUP — Duplicity doménových pravidel

- [x] **DUP-1: Predikát „je to vlastní událost spisu?“** *Opraveno
  (2026-07-27):* obě kopie volají `InfosoudOwnershipResolver::isOwn()`
  (jediný domov aliasů, senátu 0 a pivotu ročníku). Re-projekce všech
  60 spisů beze změny výsledku.

- [x] **DUP-2: „Atributy → mapa + `-` = neuvedeno“.** *Opraveno
  (2026-07-27):* všechny 4 kopie delegují na
  `InfosoudEventAttribute::mapFromDetail()/mapFromList()/cleanValue()`;
  hodnoty v mapě jsou normalizované (`''`/`'-'` → null). Zobrazovací
  smyčka v presenteru dál iteruje seznam (pořadí atributů), jen čistí
  přes `cleanValue()`.

- [x] **DUP-3: Kandidáti soudu (cache → jednání).** *Opraveno
  (2026-07-27):* `CourtCandidateService::candidatesFor()` + DTO
  `CourtCandidates::sole()`; HP fallback i validate endpoint mapují
  z něj (JS jen renderuje odpověď endpointu). Ověřeny obě větve
  evidence živě.

- [x] **DUP-4: Extrakce `JED_*` atributů.** *Uzavřeno (2026-07-27):*
  `hearing-bind` opraven s CH-2; smyčka v `infosoud-fetch-hearings` je
  záměrný **verbatim diagnostický výpis** (parsování hned pod ním jde
  přes sdílený `InfosoudHearing`) — není to divergentní pravidlo.

- [x] **DUP-5: HTTP klient 2× s různými zárukami.** *Opraveno
  (2026-07-27):* sdílený `App\Model\Http\JsonHttpClient` (UA, timeouty,
  retry jen na transport/5xx — 4xx je u infosoudu významová odpověď).
  `InfosoudClient` přes `request()`, scanner přes `attempt()` (vlastní
  retry smyčku si drží kvůli per-pokus logování; kvůli klientu si
  natahuje composer autoload, Nette DI dál nebootuje).

- [x] **DUP-6: Identita spisu jako pětice pozičních parametrů.**
  *Opraveno (2026-07-27):* `fetchCase`/`fetchEventDetail`, `getByCase`,
  `findBySpisovka` a `countPerVenueBySpisovka` přijímají `Spisovka`
  (+ soud); klient staví identitu payloadu v jednom `casePayload()`.
  **Záměrně ponecháno:** `ProceedingRelationRepository` zůstává na
  sloupcových signaturách (nullable senát kvůli NS referencím, což VO
  vyjádřit nemůže) a textové identity klíče v projekci/presenteru
  (různé podmnožiny složek; sjednocení by riskovalo víc, než ušetří —
  případně řešit se ST-1 krokem 1).

- [x] **DUP-7: Hearing klíče a čas v CLI.** *Opraveno (2026-07-27):*
  `App\Model\Hearing\HearingKey` (`venueCaseTime`/`caseTime`/
  `timeFromDb`), import i bind nad ním. Ověřeno: full-scan import
  dry-run 0 nových / 0 změněných.

- [x] **DUP-8: Zdroj dat a úroveň soudu jako magické stringy.**
  *Opraveno (2026-07-27):* enum `DataSource` (sloupce per zdroj, source
  konstanta projekce, Stats); `CourtLevel` nahradil `'ns'`/`'nss'`
  literály; `InfosoudEventType::label()/description()`
  i `InfosoudEventAttribute::label()` berou `CourtLevel`.

- [x] **DUP-9: `refSpisovka()` v presenteru.** *Opraveno (2026-07-27):*
  `SpisovkaFactory::fromEventRef()`.

- [x] **DUP-10: Mapování relation_type hardcoded vedle číselníku.**
  *Opraveno (2026-07-27):* enum `RelationType` (+ `forEventCode()`
  fallback na SOUVISEJICI), projekce nad ním. Re-projekce beze změny.

- [x] **DUP-11: „NSS neumíme“ 3×.** *Opraveno (2026-07-27):* rozhodnutí
  má jediný domov `CourtLevel::isOnInfosoud()` (oživeno z mrtvého kódu),
  HP guard přes něj; texty warningu (resolver) a chyby (HP) zůstávají
  dva — jde o různé okamžiky UI.

## ST — Struktura prezentační vrstvy

- [~] **ST-1: Rozpad `SpisPresenter` (824 ř., 28 metod, 14 závislostí).**
  Šest zodpovědností; postupné, samostatně nasaditelné kroky (rozpad
  odsouhlasen 2026-08-15, dělá se po krocích):
  1. [x] **`CaseChipFactory`** *(2026-08-15)* — `Presentation\Accessory\CaseChipFactory`
     drží `chip()`, `references()`, `courtNamedIn()`, `storedCases()`
     a `isCourtRegistry()`; presenter spadl z 896 na 772 ř. a ze 14 na 13
     závislostí (`RegistryRepository` i `SpisovkaParser` odešly do
     faktory). `relatedCourtIndex()` zůstal v presenteru — čte vazby
     konkrétního spisu (`relationRows()`), do faktory odkazů nepatří;
     půjde s krokem 3.
  2. [x] **`EventDetailService`** *(2026-08-15)* — `Model/Proceeding/EventDetailService`
     drží celý lazy fetch detailu: adresu záznamu (vlastní vs. cizí spis),
     zapamatování „upstream detail nemá“ i **integritní pojistku** (detail
     popisující jiný záznam se nikdy neuloží). Vrací
     `EventDetailResult` (enum `EventDetailOutcome` + čerstvý řádek),
     takže volající jen formuluje: web flash/redirect, CLI výpis řádku.
     Tím zmizela druhá kopie pravidel z presenteru a **třetí
     z `bin/infosoud-fetch-hearings.php`**. Ruční refresh detailu má
     explicitní `refetch: true` (cooldown zůstává na volajícím) a
     `hasUpstreamAddress()` z CH-4 se sloučila s `isAddressable()` —
     nově je přísnější v tom, že cizí soud musí být i v číselníku (fetch
     by na něm stejně skončil). Presenter: 772 → 712 ř., 13 → 12
     závislostí (`InfosoudClient` odešel).
  3. **View-factories** — `assignCaseHeader` 181–225, `buildEventsView`
     457–495, `buildRelatedView` 504–565, `buildAttributesView` 576–616,
     `buildNavazneView` 740–773 (~250 ř.); viz i ST-3 (DTO hlavičky).
  4. **`FavoriteControl`** (`UI\Control`) — hvězdička + modaly +
     signál (~50 ř. + blok `@case-header.latte:84–135`), znovupoužitelné
     na Dashboardu.
  Po rozpadu zbyde ~200 ř. Průběžně: `ownEvent()` a `isCoolingDown()`
  helpery (viz ST-7).

- [x] **ST-2: Chybové hlášky z `$this->error('…')` se nikdy nezobrazí.**
  *Opraveno (2026-08-15):* všech 11 volání nahradil
  `throw new Presentation\Error\UserFacingError('…')` a šablony
  `404`/`4xx` vypíšou jeho zprávu (jinak zůstává generický text).
  Vlastní třída je tu **fail-closed rozlišení**: `BadRequestException`
  vyrábí i framework a jeho zprávy popisují vnitřek („No route for HTTP
  request.“, „Cannot load presenter…“) — ty se uživateli ukázat nesmí.
  Vedlejší přínos: `throw` je pro statickou analýzu jasnější než volání
  vracející `never`. Přibyla `Error4xx/503.latte` (výpadek infoSoudu,
  vlastní znění + hlavička `Retry-After: 300`); mrtvá `Error5xx/503.phtml`
  se řeší v MISC-4. Ověřeno v **produkčním módu** (cookie
  `app-debug-mode=0`): neexistující spis → 404 s vlastní hláškou,
  neznámá událost → 404 s vlastní hláškou, neexistující routa → generický
  text, nedostupný upstream (zablokovaná doména v `/etc/hosts`
  kontejneru) → HTTP 503 + `Retry-After`.

- [x] **ST-3: Šablona hrabe přímo v raw upstream JSON.** *Hotovo
  (2026-08-06 a 2026-08-15, iterace dle rozhodnutí autora):* šablony už raw
  payload nečtou. `$isir` ze šablon úplně vypuštěn — řádek s insolvencí
  z detailu zmizel, vrátí se později jinou cestou. `$infosoud` nahrazen
  structem **`Model/Infosoud/InfosoudCaseOverview`** (`RawJsonStruct`
  z hydratoru 0.6.2 — verbatim struct pro cizí JSON: uložený string je
  jediný zdroj pravdy, `toJson()` ho vrací bajt po bajtu bez
  přeserializování, takže nemapované klíče nelze ztratit; původní
  `BaseStruct` byl pro cizí dokument chybná volba): znalost
  upstream klíčů (`stav`, `stavDatum`, `napad`,
  `nadrizenaOrganizace`) žije jen tam, ven vedou typové accessory
  (`status()`, `statusDate()`, `intakeKind()`, `superiorCourtName()`)
  s normalizací prázdných stringů na null; prázdný sloupec = prázdná
  instance, šablona nikdy nebranchuje na null struct.
  `CaseSummaryService::statusOf()` deleguje na tentýž struct (jediný
  vlastník klíče `stav`). *Dokončeno (2026-08-15):* rozdvojený split na
  `|` má jediný domov `InfosoudEventAttribute::splitMulti()`/
  `formatMulti()`; hodnoty `nsAttributes` chodí do šablony rovnou
  v display podobě (presenter je poskládá při stavbě `CaseHeaderView`),
  takže Latte už upstream data nepřeskládává.

- [x] **ST-4: Dashboard obchází sdílený define spisovky.** *Opraveno
  (2026-08-15, hned po ST-1 kroku 1):* `favoriteView()` staví chip přes
  `CaseChipFactory::chip()` a obě šablony (`default.latte`,
  `editFavorite.latte`) ho renderují sdíleným define `case-chip`, takže
  pravidlo „kdy je spisovka odkaz“ platí i na dashboardu (mj. spis
  s neznámým soudem tam teď nabídne hledání místo mrtvého odkazu).
  Pozn.: znovu se potvrdila past z architektury — `{varType}` nestačí,
  modal odebrání používal `$item['label']` a rozbil se až v prohlížeči.

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

- [x] **ST-8: `{varType}` drift.** *Opraveno (2026-08-15):* hlavička
  spisu jde do šablon jako jeden view-model
  `Presentation\Spis\CaseHeaderView` (readonly, display-ready hodnoty),
  takže všechny tři šablony deklarují jedinou proměnnou `$caseHeader`
  a seznamy se nemají jak rozejít; dřív jich bylo 12 a `udalost.latte`
  jich pět zamlčovala, ačkoli je include hlavičky používal.
  Doplněn `{varType … $form}` v `Dashboard/default.latte` a sjednoceno
  `?\DateTimeImmutable` s úvodním lomítkem (konvence z CLAUDE.md).

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

- [x] **AN-1: `bin/` a `migrations/` nejsou pod PHPStanem.** *Opraveno
  (2026-07-27):* cesty `../bin` a `../migrations/data` přidány (63
  souborů, level 8). Odhalené reálné vady opraveny: getopt hodnoty brané
  jako string (opakovaná volba vrací pole, flag false), nenarrowované
  výsledky insertů, mrtvý `?? ''`. Jediný přidaný ignore: `$argv`
  (CLI SAPI ho definuje vždy), scoped na CLI cesty. Prerekvizita
  typového refactoringu splněna.

- [x] **AN-2: Plošný ignore `ActiveRow` property access.** *Hotovo
  (2026-08-05).* Ignore vypínal kontrolu ~200 přístupů v celém projektu
  (překlep v názvu sloupce = tichý `null`). Odpadl s dokončením převodu na
  typové entity (viz [architektura.md](architektura.md)):
  `ActiveRow` už z modelu nevychází, zbylé výskyty jsou jen `assert()`
  u `Selection::insert()` a `instanceof` před hydratací. `web/phpstan.neon`
  na level 8 prochází bez něj.

- [~] **AN-3: Testy pokrývají jen parsování spisovky.** *Částečně
  (2026-07-27, regresní síť pro typový refactoring):* přidány
  `InfosoudHearing.phpt`, `RoomClassifier.phpt`
  a `SpisovkaSlugParser.phpt` (DB-backed, self-skip). **Zbývá:**
  fixture test `ProceedingProjectionService` (JSON → očekávané řádky —
  nejcennější, chce testovací fixture a strategii vůči DB),
  `SpisovkaResolver`, `CaseSummaryService`, `InfosoudLinkBuilder`,
  `CourtCodeResolver`. Zbytek AN sekce odložen (rozhodnutí 2026-07-27) —
  vrátí se s typovým refactoringem.

- [~] **AN-4: Chybí agregovaný check a CI.** *Částečně (2026-07-27):*
  `composer check` = phpstan + latte-lint + tester. **Zbývá:** CI
  (GitHub Actions) — odloženo.

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

- [~] **CLI-6: Sdílená CLI knihovna.** *Částečně (2026-07-27, prerekvizita
  typového refactoringu):* zápisy do `hearing*` jdou přes model —
  `HearingRepository::insert/update/insertObservationIgnore`, nový
  `HearingRoomRepository`, `classifyRoom()` extrahován do
  `App\Model\Hearing\RoomClassifier` (+ test); `HearingKey` viz DUP-7.
  **Zbývá:** `bin/_cli.php` (bootstrap + option parser + stderr/exit
  konvence) — spolu s CLI-1/2/5.

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

- [x] **MISC-1: N+1 dotazy.** *Číselníková část vyřešena (2026-08-06)
  cache snapshotem* ([analyza-ciselniky.md](analyza-ciselniky.md)):
  `getByKod`/`isCourtRegistry`/`findByNorm`/`CourtCodeResolver` = 0 SQL,
  detail spisu spadl z 93 na ~26 SELECTů; `statusOf()` už také bez SQL
  (čte jen JSON přes `InfosoudCaseOverview`) a Dashboard batchuje řádky
  spisů přes `findByIds()`. *Zbytek dodělán (2026-08-15):*
  `ProceedingRepository::findByCases()` (multi-column `IN`, klíčováno
  `CaseFile::key()`) zodpoví existenci všech case-chipů stránky jedním
  dotazem, `ProceedingEventRepository::findByCaseFiles()` +
  `CaseSummaryService::subjectsOf()` dodají předměty celé dávky jedním
  dotazem (related tabulka i Dashboard) a vazby se pro request čtou
  jednou (`relationRows()` sdílí `buildRelatedView` s
  `relatedCourtIndex`). Projekce si owner ref události počítá jednou pro
  obě fáze (`resolveOwnerRefs()` + `CaseFileEvent::takeOwnerRefFrom()`) —
  vedle úspory CPU tím zmizel i rozdvojený výpočet, který se mohl rozejít.
  Měřeno: detail spisu **27 → 9 dotazů**, Dashboard 5 dotazů nezávisle na
  počtu oblíbených (dřív +1 per spis). Ověřeno v prohlížeči (shodné čipy,
  vztahy, předměty i bookmark stavy) a aktualizací spisu: sada id
  událostí identická, 30 stažených detailů zachováno, nová vazba
  z upstreamu se korektně přidala.

- [x] **MISC-2: `Json::decode` bez ošetření v modelu + tiché selhání
  projekce.** *Opraveno (2026-08-15):* všech pět čtení uložených raw JSON
  sloupců jde přes `Proceeding\StoredJson::decode($json, $context)` —
  nečitelný payload i payload, který není objekt, končí stejnou
  `StoredJsonException` s kontextem („case file #13086 (infosoud_json)“),
  vzorem je obalování v `InfosoudClient`. Tím zmizel tichý `return`
  v `projectInfosoud()`, který projekci nechával zamrzlou na předchozím
  obsahu, aniž by se to kdokoli dozvěděl. NULL sloupec dál znamená
  „nic uloženo“ = prázdné pole, ne chybu.
  Pozn. k reálnosti scénáře: oba sloupce mají `CHECK (json_valid(...))`,
  takže nevalidní string do DB neprojde — dosažitelná je právě ta větev,
  která dřív mlčela (validní JSON, který není objekt: `null`, `123`, …).
  Ověřeno syntetickým řádkem s `infosoud_json = 'null'`: detail spisu
  místo prázdné hlavičky vyhodí výjimku s identifikací řádku; řádek po
  testu smazán, záloha tabulky v `.backups/`. Ostatní `Json::decode`
  v `bin/` (CLI, kde výjimka stejně končí hlasitým fatálem) zůstávají na
  CLI vlnu.

- [x] **MISC-3: Porovnání data události raw string vs. DB-normalizovaný
  tvar.** *Opraveno (2026-07-27, prerekvizita typového refactoringu):*
  příchozí datum se kanonizuje na `Y-m-d` při ingestu
  (`normalizedEventDate()`), detekce „změněného data“ (která záměrně
  zahazuje cached detail) tak závisí na datu, ne na reprezentaci —
  hydratace ani upstream změna formátu už nemůže spustit hromadné
  zahození detailů. Ověřeno re-projekcí: 134/134 detailů přežilo.

- [ ] **MISC-4: Mrtvý kód.** `CourtRegion` enum (celý — vypadá jako
  aktivní pravidlo, není; sloupec `region` se používá jen v datech),
  `SpisovkaResolution::isOnInfosoud()` (pozn.: `CourtLevel::isOnInfosoud()`
  už mrtvý není — oživen v DUP-11),
  `Spisovka::$attachedNumber` (parser plní, nikdo nečte),
  `Error5xx/503.phtml` (nikdy nevyžádaný), `UserRepository::findAll/
  getById/delete`, `HearingRepository::findAll`,
  `ProceedingRepository::findAll`, `Spisovka::slugifyRegistry` může být
  private, prázdný `LatteExtension` registrovaný v DI (bez komentáře
  o záměru). *Pozn.:* `ProceedingProjectionService::resetInfosoudEvents()`
  je **zdokumentovaný záměr** (roadmap + analyza-udalosti TODO) —
  nemazat; doplnit odkaz do docblocku, ať nepadne za oběť příštímu
  úklidu.

- [x] **MISC-5: Nekonzistence repository vrstvy.** *Hotovo (2026-08-06).*
  `Selection`/`ActiveRow` z modelu nevychází (typový refactoring),
  `->fetch() ?: null` idiom zmizel s ním, CRUD metody berou/vrací entity.
  Normalizace rejstříku sjednocena na `mb_strtoupper` (2026-08-06) — shodná
  s `Spisovka::registryNorm()`. Pozn.: od snapshot cache číselníků je to
  korektnost, ne kosmetika — lookupy jdou přes PHP mapy (přesná shoda),
  CI kolace DB už nic nemaskuje a `strtoupper` by `nsčr` nenormalizoval
  (jediný non-ASCII norm v číselníku je `NSČR`). Kódy soudů/prefixy
  zůstávají `strtoupper` záměrně (ASCII identifikátory infosoudu).

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
