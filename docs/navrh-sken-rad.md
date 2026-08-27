# Návrh: adaptivní sken číselných řad spisů

Plán nástroje pro systematické stahování celých číselných řad z infosoudu
(soud × rejstřík × senát × ročník) s minimem promarněných requestů. Vychází
z empirie skenů OS Ostrava T 2024–2026 (srpen 2026): hloupé stropy
„maximum × 1,25“ stály dohromady **1 261 not-found requestů**, adaptivní
algoritmus níže by tutéž práci odvedl za ~10 dotazů na řadu.

Stav: **návrh** (2026-08-28). Předpoklad — evidence missů `case_lookup_miss`
a `--skip-exists` jsou hotové (viz [architektura.md](architektura.md), sekce
*Evidence deterministických neúspěchů*); samotný skener zatím neexistuje.

## Empirie, o kterou se návrh opírá

Změřeno na kompletních řadách OS Ostrava T (2026-08-27/28):

- **Řady jsou souvislé, díry vzácné**: ~0,5 % čísel; uvnitř řady výhradně
  izolovaná jednotlivá čísla (nezveřejněné/neveřejné věci), shluky do délky 3
  jen na úplném začátku řady (novoroční artefakt). Uvnitř řady tedy nikdy
  nechybí 2+ čísla po sobě → **K souvislých missů nad kandidátem konce je
  spolehlivý stop-signál** (K = 3–5).
- **Číslo se přiděluje při fyzickém zápisu („tady a teď“)**, nikdy zpětně do
  starší řady; ročník ve značce = rok zápisu. Datum `ZAHAJ_RIZ` se ale přebírá
  z rozhodné události a u ~6 % spisů je historické (analogie EPR pasti
  v rejstříku C) → datum lze užít **jen robustně** (medián), ne per spis.
- **Plnění řad je v čase ~lineární** a rychlost per senát stabilní
  (OS Ostrava T: 5–15 věcí/měsíc dle senátu) → z data v sondě lze
  interpolovat pozici v roce, z rychlosti predikovat konec.
- Uzavřený ročník: medián zahájení posledních čísel řady leží v půlce až
  konci prosince. Když leží dřív, je strop podstřelený (ceiling-hit) — přesně
  tak se odhalilo 6 podstřelených řad 2025.

## Zadání skeneru

Vstup: seznam řad (soud, rejstřík, senát, ročník) + volitelný odhad konce.
Cíl: po doběhu je v DB **úplná řada 1..N** — každé číslo buď spis
v `case_file`, nebo dokumentovaný miss v `case_lookup_miss`; N je potvrzený
konec řady. Nástroj musí:

1. vyloučit ze skenu, co už lokálně známe (spisy i trvalé missy),
2. najít konec řady s logaritmickým počtem dotazů (žádné salvy 404),
3. řadit requesty nenápadně (žádné vzestupné běhy v logu poskytovatele),
4. zvládnout inkrementální režim (dorůstání běžícího ročníku).

## Algoritmus

### Fáze 0 — lokální inventura (0 requestů)

Per řada: držená čísla (`case_file`, `infosoud_at IS NOT NULL`), trvalé missy
(`isPermanent()`), **M = nejvyšší potvrzené číslo** (jistá spodní mez konce).
Odhad konce E, když nepřišel na vstupu:

- **rychlostí**: E = M + rychlost × (zbytek období); rychlost z dat zahájení
  držených spisů řady (robustně, bez zpětně datovaných),
- **German tank estimátor** pro řady známé jen z hrstky spisů (jednání):
  `N̂ = m + m/k − 1` (m = max viděné, k = počet viděných). Ověřeno: senát 2/2024
  k=3, m=137 → odhad 183, skutečnost 183. Pozor, náš vzorek není uniformní
  (aktivní věci) — u malých k nadstřeluje; slouží jen jako prior.

### Fáze 1 — pracovní plán

Plán = nedržená čísla 1..M (bulk výplň — jistá oblast) + stavový automat
hledání konce nad M (per řada). **Globální scheduler losuje další request
náhodně napříč všemi řadami i mezi bulk výplní a sondami konce** — stealth
je strukturální vlastnost, ne dodatečná úprava: poskytovatel vidí nesouvislý
proud spisovek napříč senáty a ročníky.

### Fáze 2 — hledání konce řady

Kombinace tří klasických metod, seřazeno podle toho, co je k dispozici:

1. **Interpolace datem (regula falsi)** — primární. Sonda, která spis najde,
   nese datum zahájení → pozice v roce → `N ≈ n × 365 / dayofyear(d)`.
   Další sondu umístit podle tohoto odhadu. Kvůli zpětně datovaným zahájením
   používat **jen pro umístění sond** (a robustně vůči okolí), nikdy pro
   závěry. Typicky 3–6 sond na řadu.
2. **Exponenciální (galloping) hledání** — když datum nepomáhá: od poslední
   potvrzené pozice sonduj s krokem s, 2s, 4s… do prvního missu. Krok je
   relativní k prohledanému, takže se sám škáluje na řady o 60 i 2 000
   spisech; startovní krok ~max(4; 5 % odhadu zbytku).
3. **Bisekce bracketu** — jakmile existuje interval [potvrzené, miss],
   půlit (nebo interpolovat) do zúžení na jednotky.

**Rozlišení díra × konec:** po missu na n otestovat n+1 (příp. n+2) — hit
znamená díru (zapsat miss, pokračovat), samé missy konec. Konec potvrzen
K = 3–5 souvislými missy. **Přestřelený odhad** (E za koncem): tatáž bisekce
v [M, E], invariant „dole existence, nahoře miss-cluster“.

**Zápis missů:** missy **pod** potvrzeným koncem = díry → `case_lookup_miss`.
Sondy **nad** potvrzeným koncem uzavřeného ročníku se neukládají (nejsou to
díry, prostor nad koncem je nekonečný) — v tabulce ale přirozeně skončí
jako vedlejší produkt sondování a to nevadí: `isPermanent()` je klasifikuje
správně a úplnost řady 1..N drží čísla pod N.

### Fáze 3 — inkrementální režim (běžící ročník)

Identický stroj s M ze spisovny a E = M + rychlost × Δt × 1,5. Náklad
~5 dotazů na senát a měsíc. Missy z minulého běhu nad tehdejším koncem nejsou
trvalé (`isPermanent()` je u běžícího ročníku nepustí), takže se přirozeně
přeověří.

## Otevřené otázky

- **Ukládat potvrzený konec řady?** Malá tabulka `series_scan` (řada, konec,
  ověřeno kdy) vs. odvozování z MAX + K-miss přeověření při každém běhu
  (~3 dotazy). První verze: neukládat, přeověřovat; tabulku přidat až podle
  praxe.
- **Formát vstupu**: soubor s řádky `soud rejstřík senát ročník [odhad]`,
  nebo výčet senátů per soud+rejstřík+ročník s auto-detekcí senátů z lokálních
  dat? Rozhodne první použití (pilot).
- **Umístění logiky**: služba v `App\Model\CaseFile` (např.
  `CaseSeriesScanService`) + tenká obálka `bin/`, běh přes aplikační log
  (`buildRunSession`), čistá logika (bisekce, interpolace) testovatelná
  nette/testerem bez DB.

## Pilot

První nasazení: **OS Ostrava, rejstřík T, ročník 2023** (další krok hledání
třídenního jednání 22.–24. 7. 2026, viz kontext skenů 2024–2026) — nástroj se
zaplatí prvním použitím. Dále dorovnání ceiling-hit řady 13 T /2024
(konec ≥ 140, strop byl 140).
