# Zadání: Lexion

Vlastní scraper/checker nad českým infoSoudem (justice.cz). Motivace: oficiální
rozhraní je uživatelsky nepřívětivé a komerční monitorovací služby jsou drahé.

## Problémy oficiálního rozhraní

- Spisovou značku je nutné zadávat po jednotlivých polích formuláře — nejde
  jednoduše vyhledávat copy-paste celé spisovky.
- Nové rozhraní (spuštěné cca začátkem 2026) je SPA s klasickými dětskými
  nemocemi — problémy s historií prohlížeče, dynamické načítání atd.
- **Chybí notifikace o nových událostech** — to je hlavní potřeba (zejména
  monitoring nařízených jednání).

## Požadované funkce (MVP)

1. **Sledování zadaných řízení** — periodická kontrola, notifikace o změnách
   (nové události, zejména nařízená jednání).
2. **Pomůcky pro práci se spisovkou** — např. vložím spisovou značku ze
   schránky (copy-paste celého textu, např. „12 C 34/2026“) a dostanu přímý
   odkaz na infosoud.
3. **Fulltextové hledání soudů** — místo hierarchie kraj/okres jedno hledací
   pole, kde lze hledat podle města: „trut“ → „Krajský soud Hradec Králové /
   Okresní soud Trutnov“.

## Budoucí rozšíření (ne teď)

- **Sledování insolvenčního rejstříku** — stát poskytuje oficiální API,
  není třeba scrapovat.
- **Archivace rozsudků NS/NSS** — rozsudky Nejvyššího (správního) soudu jsou
  veřejné vždy jen 14 dní po vyhlášení; ukládat je trvale.

## Postup

Nejdřív vybudovat základní architekturu, pak teprve jednotlivé funkce.
Před psaním kódu proběhl brainstorming nad technologiemi a architekturou.
