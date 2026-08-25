# Návrh: integrita dat — kontroly, opravy a refaktoring CLI logiky

> **Stav: NÁVRH (2026-08-22).** Vzešlo ze session o synchronizaci dat
> (docs/sync.md), kde se ukázalo, že sync zavádí druhé zapisovatele k dřív
> jednoznačným invariantům. Paralelní session narazila na podobnou potřebu
> refaktoringu ze své strany — tenhle dokument je společný podklad.
> **Hotové základy (2026-08-22):** žurnál ztrát dat `case_file_journal`
> + rozdělení projekce na plan/apply (viz *Vazba na žurnál ztrát dat* níže
> a [architektura.md](architektura.md)) a **aplikační log s běhy**
> ([logovani.md](logovani.md)), který převzal krok 2 a do kterého se
> zbývající kroky zapojují (viz *Zapojení do aplikačního logu* níže).
> **Kroky 1–4 jsou hotové** (krok 4 v rozsahu bezpečných oprav — destruktivní
> nástroje záměrně neexistují); zbývá přestavba projekce jednání z kroku 3.

## Výchozí zjištění (empiricky ověřeno 2026-08-22 na dev DB)

26 kontrol napříč schématem, **všechny tvrdé třídy nekonzistence na nule**
(projekce vs. raw JSON, vazby jednání↔spis, room_id vs. číselník síní, kolize
párovacích klíčů, ročníky, rejstříky…). Nenulové jen legitimní neúplnosti:
517 událostí bez staženého detailu (lazy fetch), 12 976 spisů jen z ISIR.
Základ je tedy čistý — mechanismus kontroly se dá postavit tak, že jakákoli
budoucí odchylka od nuly je signál.

## Proč to je potřeba: invarianty získaly druhé zapisovatele

Rozhodující otázka u každého invariantu je **kdo ho zapisuje**. Dokud jeden,
nemá jak se rozejít. Aktuální stav:

| invariant | zapisovatelé | poznámka |
|---|---|---|
| `case_file_event`/`case_file_relation` = f(`case_file.infosoud_json`) | `CaseFileProjectionService` + `Sync\CaseFileMergeService` | sync přenáší hotové řádky, projekci nepouští (záměr: `detail_json` z JSON nejde odvodit, id událostí jsou v URL). **Největší nové riziko.** |
| `hearing` = f(`hearing_observation`) | `bin/infojednani-import.php` + `Sync\HearingMergeService` | přestavba projekce **neexistuje jako služba** — logika žije jen v `bin/`, který se nedeployuje |
| `hearing.case_file_id` + `court_binding` | `bin/hearing-bind.php` + `Sync\HearingMergeService` | dtto — párování není na produkci k dispozici |
| `hearing.room_id` ↔ `hearing_room` | importér skenu + sync (`linkRoom` backfill) | fill-later sloupec, dopárování už existuje, ale volá se jen při vložení síně |
| `case_file_event.detail_json` | `EventDetailService` + sync | lazy fetch, cooldown 5 min |
| číselníky | ruční SQL migrace per prostředí | odpojené od deploye; sync rozdíly hlásí (jen court/registry/relation_type) |

Další zdroje nekonzistence: přerušený import (bezpečný díky idempotenci;
od zavedení aplikačního logu po něm zůstává `pending` záznam běhu a poslední
řádek souboru říká, na kterém záznamu skončil), ruční migrace aplikovaná jen
na jedné straně, ruční zásahy do DB.

## Tři kategorie kontrol (nemíchat — jinak šum utopí signál)

1. **Nesrovnalost** — má být vždy 0; nenulová = chyba k řešení. (Projekce
   nesedí s rawem; `confirmed` bez spisu; vazba na spis s jinou značkou;
   kolize párovacího klíče cizích událostí.)
2. **Neúplnost** — očekávaně nenulová; zajímá trend a možnost dopárování.
   (`room_id` NULL a síň v číselníku existuje; jednání navázatelné a
   nenavázané; událost bez detailu.)
3. **Legitimní díra** — nesmí se hlásit vůbec. (`dst_registry_norm` ZK/ZT —
   spisy státního zástupce; `ref_*` mířící na nenačtený spis; síň bez
   `retired_at`.) Přesně kvůli téhle kategorii skončily číselníky v syncu
   u varování místo blokace — viz docs/sync.md.

## Navržené kroky (pořadí záměrné)

1. **System → „Kontrola dat“** — ✅ **HOTOVO 2026-08-23**
   (`App\Model\Integrity\`, stránka `/system/integrity`). Kontroly jsou
   deklarativní data (`IntegrityCheck`: slug + kategorie + české texty +
   read-only SQL typované jako literal-string), `IntegrityService` je
   spouští, presenter jen vypisuje. 15 kontrol: 11 nesrovnalostí (vč.
   žurnálu za 30 dní) + 4 neúplnosti; legitimní díry kontrolu nemají
   záměrně a `IntegrityCategory` pro ně ani nemá case. Zobrazení stránky
   se neloguje, signál „Zapsat stav do logu“ = instantní záznam
   `integrity`/`check` s počty per slug v `data`. Ověřeno na nastražené
   nesrovnalosti i neúplnosti (červený badge, vzorky, log záznam).
2. **Evidence běhů** — ✅ **HOTOVO 2026-08-22, převzal obecný aplikační log**
   ([logovani.md](logovani.md)): sync import je běh (pending → ok/failed,
   průběh a skip-problémy v souborech, celý report v `result_data`), export
   instantní záznam, CLI tooly jednání běhy. Tracy kanál `'sync'` zanikl.
3. **Refaktoring `bin/` → `web/app/Model/`** — ✅ **HOTOVO 2026-08-23**
   (extrakce obou toolů; přestavba projekce jednání z pozorování zůstává
   samostatný budoucí krok) — viz níže.
4. **Opravné akce** — ✅ **HOTOVO 2026-08-23** (bezpečná část). Kontrola,
   která má opravu, ji deklaruje slugem (`IntegrityCheck::$repair`);
   presenter dispatchuje, služby vlastní běh. Dvě opravy: **`link-rooms`**
   (plošné dopárování `hearing.room_id`, `HearingRoomLinkService` +
   `HearingRepository::linkAllRooms()`, jeden aditivní UPDATE) a
   **`bind-hearings`** (volá `HearingBindService::bind()` — fáze confirm jen
   čte cache detailů, nic nestahuje). Obě s dry-run (taky běh v logu,
   `HearingLogKind::RoomLink`/`Bind`) a potvrzovacím modalem; nikdy jako
   vedlejší efekt importu — import zůstává hloupý: přeskoč, zaloguj, jeď dál.
   - **Nebezpečné, nikdy automaticky** (tlačítko záměrně neexistuje —
     případný budoucí nástroj musí vypsat plán a chtít potvrzení ztrát):
     přeprojektování spisu z rawu
     (`CaseFileProjectionService` maže řádky chybějící v čerstvé timeline;
     `case_file_event.id` je v URL a nese `detail_json`, který se z JSON
     neobnoví — oprava s cenou, dry-run musí říct „zahodím N událostí, z toho
     M se staženým detailem“). Potvrzování vazeb jednání = stahování
     z justice, patří do fronty, ne do tlačítka.

## Refaktoring: logika z `bin/` do `web/app/Model/` (krok 3)

Strukturální nález: `bin/` se nedeployuje (deploy nahrává jen `web/`), takže
produkce dnes **nemá čím** přestavět projekci jednání ani dopárovat vazby.
Dokud to platí, produkce je závislá na tom, že jí dev pošle správně spočítaná
data — přesně ta jednosměrná závislost, kterou má sync odstraňovat.

Navržený cílový stav (tenké CLI = parsování argumentů + výpis, veškerá logika
ve službách):

| dnes v `bin/` | služba | stav |
|---|---|---|
| `infojednani-import.php` (merge scan → `hearing*`) | `App\Model\Hearing\HearingScanImportService` | ✅ 2026-08-23; per-jednání pravidla sjednocena do `HearingMergeRules` (čistá funkce, volá ji importér i `Sync\HearingMergeService`, test `HearingMergeRules.phpt`) — tím se importér naučil i doplňování chybějící síně, které dřív uměl jen sync |
| `hearing-bind.php` (guess/confirm párování) | `App\Model\Hearing\HearingBindService` | ✅ 2026-08-23; fáze venue_guess je čistě DB (bezpečná oprava), fáze confirm čte jen cache detailů (nestahuje) |
| přestavba `hearing` z `hearing_observation` | vznikne jako metoda `HearingScanImportService` (či vedle ní) | ❌ zbývá; navrhnout ve stylu plan/apply (viz *Vazba na žurnál ztrát dat*) |

Obě služby vlastní svůj logovaný běh (CLI je tenká obálka s progress
callbackem) a ekvivalence s původními tooly byla ověřena porovnáním dry-run
výstupů staré a nové verze na reálném skenu (identické počty).

Logovací důsledek vytažení (**provedeno**): běh vlastní služba, ne CLI —
běhy tak vznikají i při volání z webu, oprav či budoucí fronty a CLI jen
vypisuje přes progress callback.

`bin/infosoud-fetch*.php` a `bin/create-user.php` už dnes jen volají služby —
tam není co řešit. `bin/infojednani-scan.php` (HTTP sken do `.data/`) zůstává
v `bin/` záměrně: potřebuje souborový výstup a dlouhý běh, na produkci nemá co
dělat.

**Pozn. pro druhou session:** pokud tamní úkol potřebuje tutéž logiku
z jiného kontextu (web, fronta, cron…), je to další argument pro služby
v `web/app/Model/` — doplňte sem svoje požadavky (rozhraní, granularita,
transakce) a sjednotíme názvy a řezy dřív, než se začne implementovat.

## Zapojení do aplikačního logu (doplněno po dokončení logu)

Jak zbývající kroky používají [logovani.md](logovani.md):

- **Kontroly (krok 1):** zobrazení stránky se neloguje (čtení). Explicitní
  „spustit kontroly“ se zapíše jako **instantní záznam** (`integrity`/`check`,
  výsledné počty per kontrola do `data`) — kontroly jsou rychlé SQL, běh se
  soubory je zbytečný. `result_data` je záměrně jen pro běhy (odděluje
  výstup uzávěrky od vstupních `data`, aby se nepřepisovaly); instantní
  záznam má vše u sebe už při zápisu, takže počty patří do `data`
  (rozhodnuto 2026-08-23).
- **Opravy (krok 4):** každá oprava = **běh** s vlastním `LogEventKind`
  (vzor `HearingLogKind`, `dryRun` v `data` — dry-run je taky běh). U
  destruktivních oprav se vytištěný `CaseFileProjectionPlan` zapisuje do
  kanálu běhu (JSONL), takže „co přesně se zahodilo“ zůstane i mimo žurnál;
  žurnál sám se při `apply()` zapíše automaticky.
- **Extrahované služby (krok 3):** vlastní běh otevírá služba, ne CLI —
  viz logovací důsledek v sekci refaktoringu.

## Vazba na žurnál ztrát dat (implementováno 2026-08-22)

Druhá session dodala společný základ, o který se kroky výše mohou opřít:

- **Dry-run opravných akcí (krok 4) existuje zadarmo:** projekce je rozdělená
  na `CaseFileProjectionService::plan()` (čistý diff bez zápisů) a `apply()`.
  „Zahodím N událostí, z toho M se staženým detailem“ = vypsat
  `CaseFileProjectionPlan` (`isDestructive()`, `lossContext()`, veřejné
  seznamy insertů/updatů/deletů) místo jeho aplikace. Opravný nástroj, který
  destruktivní plán aplikuje, žurnál dostane automaticky — `projectInfosoud()`
  zaznamenává destrukci sám (`resetInfosoudEvents()` nikoli, viz komentář
  u něj).
- **Kontroly (krok 1) mají na co odkazovat:** typy `JournalEntryType` jsou
  vyjmenovatelné a stabilní (CHECK v DB); kontrola „výskyty v žurnálu za
  posledních X dní“ může být jednou z kontrol kategorie 1.
- **Sanity guard hromadného mazání (odloženo, zjištění paralelní session):**
  projekce dnes smaže bez limitu vše, co v čerstvé timeline chybí — oříznutá
  odpověď API by tiše smazala většinu paměti spisu (žurnál to zaznamená, ale
  nezastaví). Práh „zmizí-li nápadná část událostí, neprovést a nechat
  k ručnímu posouzení“ se navrhne až podle reálných výskytů v žurnálu.
- **Evidence běhů (krok 2) zůstává oddělená vrstva:** žurnál je paměť ztrát
  dat per spis, aplikační log provozní paměť běhů — nespojovat.

## Návrh: evidence tichých změn nařízených jednání (zadáno 2026-08-25)

Potřeba: soudy mění nařízená jednání „tiše“ (zrušení beze stopy, přesun na
jiný termín) a my to chceme při aktualizaci spisu zachytit, trvale evidovat
a **ukázat u události poznámku**. Empiricky (první sklizeň žurnálu
2026-08-25, rozbor v [analyza-udalosti.md](analyza-udalosti.md)): zmizelá
`ST_VEC_VYR` jsou stavový marker vyřízení, který se při novém rozhodnutí
přesouvá (očekávané chování, ne ztráta), zmizelé `PRED_VEC` vazby jsou
falešné nálezy projekce; `NAR_JED` zatím žádný — změny se zachytí jen při
refreshi a spisy zatím aktualizujeme málo (systémová odpověď = monitoring
z roadmapy, žurnál pak chytá automaticky). Z toho plyne požadavek na
interpretační vrstvu navíc: **klasifikovat podle kódu události** — zmizelé
`ST_VEC_VYR` je rutina, zmizelé/přesunuté `NAR_JED` je přesně ta změna,
kterou chceme ukázat. Druhá opora: tatáž sklizeň ukázala, že smazání
záznamu ostatním `poradi` neposouvá — párovací klíč je v tomhle scénáři
stabilní identita a „zmizelé poradi = skutečné smazání“, na čemž může
interpretace stavět.

**Vrstva faktů existuje** — `case_file_journal.context` nese `droppedEvents`
(kód + datum zmizelé události) a `droppedDetails` (`dateBefore`/`dateAfter`
= přesun na stejném pořadí). Chybí **vrstva interpretace a viditelnosti**:

1. **Poznámka u přesunuté události** — řádek přežívá (update) a žurnál zná
   jeho id → detail spisu/události čte žurnál a ukáže badge „evidujeme
   změnu: termín přesunut z X na Y“. Bez změny schématu, zpětně funkční.
2. **Duchové na timeline** — zmizelá událost řádek nemá (smazán vč. URL),
   poznámka žije na úrovni spisu: timeline renderuje ze žurnálu položku
   „jednání zmizelo z infoSoudu (zachyceno D)“.
3. **Párování delete+insert = pravděpodobný přesun** (jiné poradi) — odhad,
   patří výhradně do interpretační vrstvy, nikdy do žurnálu.

**Doporučení: kroky 1+2 čtením žurnálu, žádná nová tabulka.** Samostatná
tabulka `case_file_event_change` (plněná při `apply()`, přirozené klíče,
syncovatelná) má smysl, až bude potřeba dotazovat změny napříč spisy nebo
přenášet poznámky syncem — žurnál se nepřenáší, takže poznámky vzniknou jen
tam, kde běžel refresh. Pozn.: falešný důvod téhle potřeby („plyne nám to
z event-projection-count“) byl bug kontroly — materializované vícetermíny
nejsou v top-level `udalosti[]`; opraveno 2026-08-25.

## Otevřené otázky (k dořešení mezi sessions)

- Zrcadlit do žurnálu i skip-problémy importu (`SyncProblemReason::
  EventMissingInNewerSnapshot`/`EventDateMismatch`)? Nic neničí (merge je
  aditivní), ale jsou to pozorování téhož driftu z nezávislého zdroje —
  zatím zůstávají jen v reportu importu a aplikačním logu.

- Kam s registry kontrol: statický seznam tříd vs. tagovaná auto-registrace
  přes DI (`search:`)?
- ~~`sync_run`: jen sync, nebo obecná tabulka „běhů úloh”?~~ Zodpovězeno
  2026-08-22: obecná tabulka `log` ([logovani.md](logovani.md)).
- Notifikace při nenulové nesrovnalosti (kategorie 1): zatím jen červené číslo
  v System dashboardu, nebo rovnou e-mail? (Monitoring/notifikace zatím
  neexistují — nejspíš jen UI.)
