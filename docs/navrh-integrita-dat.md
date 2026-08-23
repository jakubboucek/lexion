# Návrh: integrita dat — kontroly, opravy a refaktoring CLI logiky

> **Stav: NÁVRH (2026-08-22).** Vzešlo ze session o synchronizaci dat
> (docs/sync.md), kde se ukázalo, že sync zavádí druhé zapisovatele k dřív
> jednoznačným invariantům. Paralelní session narazila na podobnou potřebu
> refaktoringu ze své strany — tenhle dokument je společný podklad. Kroky
> 1–4 níže zatím implementované nejsou; **hotový je příspěvek druhé session
> (2026-08-22): žurnál ztrát dat `case_file_journal` + rozdělení projekce na
> plan/apply** — viz sekce *Vazba na žurnál ztrát dat* níže a
> [architektura.md](architektura.md), sekce *Žurnál ztrát dat*.

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

Další zdroje nekonzistence: přerušený import (bezpečný díky idempotenci, ale
nezanechá stopu — report žije jen v HTTP odpovědi), ruční migrace aplikovaná
jen na jedné straně, ruční zásahy do DB.

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

1. **System → „Kontrola dat“** (read-only stránka). Pevný seznam pojmenovaných
   kontrol rozdělený do tří kategorií výše; každá vrací počet + pár vzorků.
   Nic nemění, bezpečné kdykoli i na produkci. Nejvyšší hodnota za nejméně
   práce — hlídá přesně ty invarianty, které sync obchází.
   - Návrh umístění: `App\Model\Integrity\` — každá kontrola samostatná třída
     (název, kategorie, SQL/dotaz, vzorky), registr kontrol, presenter jen
     vypisuje. Kontroly popsat deklarativně, ať jsou vyjmenovatelné a dá se na
     ně odkazovat z logu.
2. **Evidence běhů syncu** — tabulka `sync_run` (kdy, odkud, sada, část,
   výsledné počty, doběhl/spadl). Dnes report zmizí s odpovědí; přerušený
   import po sobě nenechá stopu. (Nejde o kontrolu integrity dat, ale o
   provozní paměť, bez které se incident nedá zpětně vyšetřit.)
   **HOTOVO 2026-08-22 — nahrazeno obecným aplikačním logem**
   ([logovani.md](logovani.md)): import se zapisuje jako běh (DB záznam
   start/konec, průběh a skip-problémy v souborech běhu, celý report jako
   result payload), Tracy kanál `'sync'` zanikl. Tenhle dokument krok 2 už
   jen konzumuje; budoucí opravné akce a kontroly (kroky 1 a 4) mají běhy
   používat taky.
3. **Refaktoring `bin/` → `web/app/Model/`** — viz níže. Musí předcházet
   opravným akcím: bez něj produkce nemá čím opravovat.
4. **Opravné akce** u kontrol, kde jsou bezpečné (idempotentní, nemažou):
   dopárování `room_id` (`HearingRepository::linkRoom` per síň, nebo plošně),
   dopárování `hearing.case_file_id` (fáze venue_guess). Každá s dry-run
   a explicitním potvrzením, **nikdy jako vedlejší efekt importu** — import
   zůstává hloupý: přeskoč, zaloguj, jeď dál.
   - **Nebezpečné, nikdy automaticky:** přeprojektování spisu z rawu
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

| dnes v `bin/` | navrhovaná služba | pozn. |
|---|---|---|
| `infojednani-import.php` (merge scan → `hearing*`) | `App\Model\Hearing\HearingImportService` (název upřesnit) | stejná merge pravidla už dnes duplikuje `Sync\HearingMergeService` — sjednotit do jednoho místa, sync i importér je budou volat |
| `hearing-bind.php` (guess/confirm párování) | `App\Model\Hearing\HearingBindService` | fáze venue_guess je čistě DB (bezpečná oprava); fáze confirm stahuje z infosoudu (fronta) |
| přestavba `hearing` z `hearing_observation` | zatím neexistuje nikde — vznikne jako metoda téže služby | migrace ji slibuje („projection can be rebuilt at any time“) |

`bin/infosoud-fetch*.php` a `bin/create-user.php` už dnes jen volají služby —
tam není co řešit. `bin/infojednani-scan.php` (HTTP sken do `.data/`) zůstává
v `bin/` záměrně: potřebuje souborový výstup a dlouhý běh, na produkci nemá co
dělat.

**Pozn. pro druhou session:** pokud tamní úkol potřebuje tutéž logiku
z jiného kontextu (web, fronta, cron…), je to další argument pro služby
v `web/app/Model/` — doplňte sem svoje požadavky (rozhraní, granularita,
transakce) a sjednotíme názvy a řezy dřív, než se začne implementovat.

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
- **`sync_run` (krok 2) zůstává oddělený záměr:** žurnál je paměť ztrát dat
  per spis, `sync_run` provozní paměť běhů — nespojovat.

## Otevřené otázky (k dořešení mezi sessions)

- Zrcadlit do žurnálu i skip-problémy importu (`SyncProblemReason::
  EventMissingInNewerSnapshot`/`EventDateMismatch`)? Nic neničí (merge je
  aditivní), ale jsou to pozorování téhož driftu z nezávislého zdroje —
  zatím zůstávají jen v reportu importu a aplikačním logu.

- Granularita `HearingImportService`: jedna služba pro „merge jednoho jednání
  + observace“ (volaná importérem, syncem i budoucí přestavbou), nebo zvlášť
  merge-pravidla a zvlášť orchestrace?
- Kam s registry kontrol: statický seznam tříd vs. tagovaná auto-registrace
  přes DI (`search:`)?
- ~~`sync_run`: jen sync, nebo obecná tabulka „běhů úloh”?~~ Zodpovězeno
  2026-08-22: obecná tabulka `log` ([logovani.md](logovani.md)).
- Notifikace při nenulové nesrovnalosti (kategorie 1): zatím jen červené číslo
  v System dashboardu, nebo rovnou e-mail? (Monitoring/notifikace zatím
  neexistují — nejspíš jen UI.)
