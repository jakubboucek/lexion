# Pokrytí číselných řad ve spisovně

Evidence řad (soud × rejstřík × senát × ročník), které byly systematicky
proskenované — každé číslo 1..konec je buď spis v `case_file`, nebo
dokumentovaný miss. Záznam pro obsluhu a Claude, žádný nástroj z něj nečte
(viz [navrh-sken-rad.md](navrh-sken-rad.md), sekce *Rozhodnutí*). Řady mimo
tento soubor jsou ve spisovně jen oportunisticky (jednání, ruční fetch, web).

Pozn.: skeny před 2026-08-28 běžely přes `bin/infosoud-fetch.php` s pevnými
stropy, **před zavedením `case_lookup_miss`** — jejich díry a konce jsou
doložené jen průběhem skenu (not found v logu běhu), ne řádky missů v DB.

## OS Ostrava (OSSEMOS), rejstřík T

Skenováno 2026-08-27/28 (hledání třídenního jednání 22.–24. 7. 2026; nalezeno
nakonec u KS — 49 T 5/2026). Senáty 7, 10 a 13 viz poznámky.

| ročník | senáty | stav | poznámky |
|---|---|---|---|
| 2026 | 1–6, 8–12, 14, 15, 71–74 | úplné k datu skenu (živý ročník) | konec = stav řady ~konec 8/2026; senát 13 v ročníku neexistuje; díry: 3 T {2,3}, 4 T {1–3}, 6 T 89, 11 T 104, 71 T 20, 74 T 72 |
| 2025 | 1–6, 8–15, 71–74 | úplné, konce potvrzené | konce: 1→60⁺, 2→184, 3→166, 4→186, 5→187, 6→183, 8→185, 9→183, 10→60⁺, 11→185, 12→136, 13→60⁺, 14→165⁺, 15→142⁺, 71→80, 72→147, 73→153⁺, 74→184; ⁺ = strop dorovnán následným během (viz git log 2026-08-28) |
| 2024 | 1–6, 8, 9, 11–15, 71–74 | úplné, konce potvrzené | konce: 1→74, 2→183, 3→173, 4→87, 5→130, 6→184, 8→211, 9→182, 11→181, 12→146, **13→140 = strop, NEDOŘEŠENO** (řada pokračuje), 14→180, 15→148, 71→59, 72→146, 73→163, 74→180; senáty 7 a 10 v ročníku neexistují (sondy 1..40/1..60 prázdné) |

## KS Ostrava (KSSEMOS), rejstřík T

Skenováno 2026-08-27 ručně sestaveným seznamem (mimo tuto evidenci — rozsah
a konce řad nebyly systematicky ověřovány); ve spisovně 967 spisů.
