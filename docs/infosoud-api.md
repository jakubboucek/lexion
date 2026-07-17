# infoSoud — neoficiální API (reverse-engineered)

Zjištěno 2026-07-17 průzkumem nového rozhraní. Nový infosoud běží na
**https://infosoud.gov.cz** (staré `infosoud.justice.cz` tam přesměrovává).
Frontend je Angular SPA, backend Spring Boot na `/api/v1/*` — **veřejné JSON
API bez autentizace**. Není potřeba scrapovat HTML.

⚠️ API je neoficiální (interní pro SPA) — může se kdykoli změnit bez varování.
Ukládat surové odpovědi, verzovat klienta, hlídat selhání/změnu schématu.

## Endpointy

### Číselníky (GET)

- `GET /api/v1/organizace/lov` — krajské + vrchní soudy:
  `[{"nazev":"Krajský soud Hradec Králové","kod":"KSVYCHK"}, …]`
- `GET /api/v1/organizace/podrizene/lov` — všechny okresní/obvodní soudy
  (stejný formát; kódy např. `OSVYCTU` = OS Trutnov, `OSPHA01` = ObS Praha 1).
  Query parametry zjevně ignoruje — vrací vždy vše.
- `GET /api/v1/spisova-znacka/druh/lovkod?typ=rizeni` — druhy věcí (rejstříky):
  `[{"kod":"T"},{"kod":"C"},{"kod":"NC"},{"kod":"INS"},{"kod":"EXE"}, …]`
- `GET /api/v1/env` — konfigurace prostředí pro SPA
- `GET /api/v1/stranka/hlasky?jazyk=cs-CZ&druhStranky=DULEZ_INFO` — CMS hlášky

### Vyhledání řízení (hlavní endpoint)

```
POST /api/v1/rizeni/vyhledej
Content-Type: application/json

{
  "typOrganizace": "VSECHNY_KRAJE",
  "okresniSoud": "OSVYCTU",
  "cisloSenatu": "6",
  "druhVeci": "C",
  "bcVec": "1",
  "rocnik": "2023"
}
```

Odpověď (200) — kompletní řízení včetně historie událostí:

```json
{
  "cislo": 6, "druh": "C", "rocnik": 2023, "bcVec": 1,
  "nadrizenaOrganizace": "Krajský soud Hradec Králové",
  "organizace": "Okresní soud Trutnov",
  "typOrganizace": "os",
  "stav": "Odškrtnutá - evidenčně ukončená věc",
  "stavDatum": "19.04.2023",
  "udalosti": [
    {"udalost": "ZAHAJ_RIZ", "poradi": 1, "datum": "2022-12-30",
     "zruseno": false, "znackaId": {…}, "jednani": []},
    {"udalost": "NAR_JED", …},
    {"udalost": "VYD_ROZH", …}
  ]
}
```

**Pozor — quirk:** „nenalezeno" vrací **HTTP 400** s tělem
`{"status":400,"message":"RIZENI_0000#6 C 1 / 2023#Okresní soud Trutnov",…}`.
Kód `RIZENI_0000` = řízení neexistuje; nutno odlišit od skutečné chyby requestu.

Pozorované kódy událostí: `ZAHAJ_RIZ` (zahájení), `NAR_JED` (nařízené jednání
— klíčové pro notifikace), `VYD_ROZH` (vydání rozhodnutí), `ST_VEC_VYR`,
`ST_VEC_PUK`. Událost `jednani: []` — detail jednání má zřejmě vlastní
strukturu/endpoint (záložka „Informace o jednání" v SPA, zatím neprozkoumáno).

## Deep-linky do SPA

Detail řízení (ověřeno, funguje):

```
https://infosoud.gov.cz/InfoSoud/detail-rizeni?typOrganizace=VSECHNY_KRAJE&okresniSoud=OSVYCTU&cisloSenatu=6&druhVeci=C&bcVec=1&rocnik=2023
```

Pro krajský soud jako první instanci bude payload/URL nejspíš používat jiné
pole než `okresniSoud` (neprozkoumáno — SPA má select krajský/vrchní zvlášť).
Další routy SPA: `detail-jednani`, `detail-udalosti`, `napoveda`, `error`.

## Omezení dat (uvádí přímo infosoud)

- Jen spisové značky okresních soudů s ročníkem > 2006 a krajských > 2007.
- Neobsahuje rozhodnutí ani údaje o účastnících — jen procesní události.
- Insolvence → ISIR (isir.justice.cz, má oficiální API), NSS → nssoud.cz.
