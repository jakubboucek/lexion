# Synchronizace dat mezi prostředími

Dev i produkce nabírají data nezávisle a obě strany drží kusy, které ta druhá
nemá. Data z infoJednání jsou navíc **nenávratná** — infoJednání ukazuje jen
klouzavé 30denní okno, takže co se tenkrát nenaskenovalo, už nikdy nepůjde
získat. Cílem je proto **aditivní sloučení**, ne kopie databáze.

Stav: **hotová je fáze 1** — spisy (`case_file`), jejich události
(`case_file_event`) a vazby (`case_file_relation`). Jednání (`hearing`,
`hearing_observation`, číselník `hearing_room`) přijdou v další fázi.

Kód žije v `web/app/Model/Sync/`, obsluha je v sekci **System**
(`/system/sync/export`, `/system/sync/import`, jen pro přihlášené).

## Proč to jde jednoduše

Dvě vlastnosti stávajícího schématu udělaly většinu práce:

1. **Přirozené klíče jsou všude.** Identita spisu je pětice
   (soud, rejstřík, senát, číslo, ročník), vazba je klíčovaná oběma pěticemi,
   jednání pěticí + datem a časem. Surrogate `id` je proto čistě lokální věc —
   **v přenosovém souboru se žádné neobjeví** a příjemce si parenta dohledá
   podle přirozeného klíče. Tím padá problém „stejný spis má v každém
   prostředí jiné ID“.
2. **Data mají vrstvu raw a vrstvu odvozenou.** Kdyby bylo potřeba, události
   a vazby jde přegenerovat z `case_file.infosoud_json`
   (`CaseFileProjectionService`). Fáze 1 je pro jistotu přenáší jako řádky
   (jsou malé a `detail_json` stejně odvodit nejde), ale ta možnost zůstává
   jako záchranná brzda.

## Formát souboru

JSONL, jeden záznam na řádek, každý s rozlišovačem `type`
(`App\Model\Sync\SyncFormat`, `RecordType`). **Pořadí řádků je součástí
kontraktu:**

1. `meta` — jméno formátu, **verze formátu**, čas a host odesílatele, číslo
   části. Verze je jediná brána kompatibility: jde o jedno celé číslo bez
   okna zpětné kompatibility, protože obě strany jsou naše vlastní deploye.
   Změna tvaru kteréhokoli záznamu = zvýšit `SyncFormat::Version`.
2. `codelist` — **jeden záznam na číselník** (`court`, `registry`,
   `relation_type`) pro porovnání. Dřív to byl jediný řádek se vším, což
   dělalo ~38 kB dlouhou nečitelnou řádku. `court_prefix` se schválně
   nepřenáší: mapuje ISIR prefixy pro parser spisovky a žádný přenášený řádek
   na něj neodkazuje, takže by mohl jen falešně blokovat.
3. první záznam, který není `codelist`, začíná data. Každý datový záznam nese
   všechno, co pod něj patří — `case_file` své události a vazby, `hearing`
   svá pozorování a identitu navázaného spisu, `hearing_room` sám sebe.

Tím obě strany pracují v konstantní paměti: čtenář má po jednom řádku vše, co
k rozhodnutí potřebuje, a může to zahodit dřív, než načte další.

**Raw JSON sloupce cestují jako řetězce**, ne jako vnořené objekty — jsou to
doslovné otisky toho, co řekl zdroj, takže se kopírují byte po bytu a nikde se
nedekódují a nekódují zpátky. Soubor je proto pro člověka hůř čitelný; je to
záměrná daň za věrnost.

Export je **gzipovaný** (`.jsonl.gz`, ~15:1 — celá spisovna se vejde do ~1 MB).
Import čte přes zlib, takže spolkne i rozbalený `.jsonl`.

## Sloučení

Jednosměrné: jedna strana exportuje, druhá importuje. Kdo je která, je věc
běhu, kód je symetrický. Merge je **aditivní** — nikdy nic nemaže a nikdy
nepřepíše novější hodnotu starší. Z toho plynou tři vlastnosti, na kterých
stojí celý provoz:

- **idempotence** — druhý běh téhož souboru nezmění nic,
- **komutativita** — na pořadí souborů nezáleží,
- **monotonie** — starý soubor aplikovaný omylem je neškodný.

Proto se části smí nahrávat v libovolném pořadí, opakovaně, a nedokončený
import se prostě zopakuje.

**Rozhoduje doménová čerstvost, ne `updated_at`.** Vítěze určují značky, které
říkají, kdy se data stáhla ze zdroje — `infosoud_at`, `isir_at`,
`detail_fetched_at`. `updated_at` na přijímací straně znamená „kdy jsem to
importoval“, takže by čerstvý import prohrál s vlastním účetnictvím. Zdroje se
váží zvlášť: spis, který má tady novější infoSoud a tam novější ISIR, skončí
s nejnovějším z obojího. `created_at` se drží to dřívější z obou stran — je to
taky data („kdy jsme spis poprvé viděli“).

**Události se váží zvlášť od spisu.** Které události existují a jaká mají data,
patří ke snapshotu spisu; stažený detail události je vlastní akvizice. Jedno
prostředí tak může mít novější spis a starší detail zároveň a obě poloviny
dojdou tam, kam patří.

**Vazby se jen sjednocují** podle přirozeného klíče; ručně založené (`source =
manual`) tím pádem přežijí vždycky.

### Jednání

**Pozorování jsou čisté sjednocení.** Pozorování je neměnný fakt („tenhle zdroj
tohle řekl v tenhle okamžik“) klíčovaný čtveřicí (jednání, zdroj, `observed_at`,
síň), takže sloučení dvou prostředí je množinové sjednocení a konflikt v něm
nejde ani vyjádřit. Zapisuje se stejným `INSERT IGNORE`, jaký používá importér
skenu, takže opakovaný soubor nezapíše nic. **Tohle je ta nejcennější část
celého syncu** — infoJednání ukazuje klouzavé 30denní okno, takže jednání, které
nikdo tenkrát nenaskenoval, je pryč nadobro.

**Atributy jednání se řídí `last_seen_at`**, přesně jako v
`bin/infojednani-import.php`: čerstvější spatření vyhrává pro druh, soudce,
zrušení, neveřejnost a výsledek. **Síň se ale jednou nastavená nepřepisuje** —
jednání se občas objeví ve dvou síních, první zůstává primární a obě se stejně
uchovají jako pozorování. Prázdné `room_id` se doplní, jakmile síň v číselníku
existuje.

**Vazba na spis se jen posiluje.** `court_binding` říká, jak silně věříme, že
jednání patří ke spisu, a odkaz cestuje jako identita spisu, nikdy jako id.
Odhad smí nahradit potvrzení (které umí i převázat na řízení u jiného soudu —
„infoSoud wins“, viz `bin/hearing-bind.php`), ale potvrzení se nikdy nedegraduje
ani nepřeváže. Když spis na přijímací straně ještě není, vazba se prostě
nenastaví a doskočí při dalším běhu.

### Jednací síně

Síně jsou **data, ne číselník** — sbírá je sken a ručně se kurátorují, takže se
obě strany legitimně liší a musí se slučovat, ne shodovat. `first_seen` se drží
nejstarší, `last_seen` nejnovější a spolu s ním i `retired_at` (čerstvější
spatření vlastní životní cyklus). **Kurátorování se nikdy nepřepisuje**:
zatřídění (`kind` + `off_site`) se převezme jen tam, kde je místní ještě
`unknown`, poznámka jen tam, kde žádná není.

## Kde to může prasknout

**Párování událostí je křehké místo.** Události se párují přes
`CaseFileEvent::pairingKey()`, který stojí na upstream `poradi` — a to není
v čase stabilní. Import proto kontroluje dvě věci a při obojím **spis
nesáhne, zaloguje problém a jde dál** (log `web/log/sync.log`, přehled
i na stránce importu):

- novější z obou snapshotů nezná událost, kterou starší zná (aditivní merge
  říká, že událost nemizí),
- spárované události mají různé datum.

Obojí je podpis přečíslování; hádat by znamenalo přilepit detail jedné události
k jiné. Až se problém `poradi` vyřeší jinak, dá se tohle uvolnit.

**Číselníky se hlídají tam, kde reálně můžou něco rozbít.** Rozlišují se dvě
věci:

- **Obsahový rozdíl řádku = varování**, ne zastavení. Prošli jsme, k čemu se
  hodnoty používají: `court.slug` a `registry.slug` řídí adresy, `registry.code`
  a `relation_type.label` zobrazení, `court.level` seskupování. Nic z toho
  nemůže importovaná data poškodit — nejhorší následek je, že stejný spis má
  v obou prostředích jinou adresu. Porovnání si přesto necháváme jako
  **detektor rozjetých prostředí**: číselníky spravují ruční migrace odpojené
  od deploye, takže verze formátu půlku aplikované migrace nezachytí a sync je
  první místo, kde se to ukáže.
- **Chybějící klíč, na který data ukazují = přeskočení spisu.** Z přenášených
  tabulek vedou do číselníků přesně **dva tvrdé FK**: `case_file.court_kod →
  court.kod` a `case_file_relation.relation_type → relation_type.code`.
  Kontrolují se per spis, takže jeden neznámý soud stojí hrstku spisů, ne celý
  běh; po doplnění číselníku migrací se soubor jen pustí znovu (import je
  idempotentní) a přeskočené spisy doskočí.

U jednání a síní je tvrdý FK jen na `court` (soud síně) — chybějící soud
přeskočí to jedno jednání nebo síň.

**Ostatní odkazy tvrdé nejsou schválně** a hlídat je nelze — `registry_norm`,
`ref_court_kod`, `dst_court_kod` a `dst_registry_norm` FK nemají, protože
protějšek nemusí existovat. Reálný důkaz: v datech jsou vazby s
`dst_registry_norm` = `ZK` / `ZT` (spisy státního zástupce), které číselník
rejstříků vůbec nezná. Kdyby byla přítomnost rejstříku tvrdou branou, import by
padal na legitimních datech.

Číselníky se čtou z cachovaného snapshotu, tedy přesně z toho, co používá
aplikace — **ruční číselníková migrace bez smazání cache je pro sync
neviditelná stejně jako pro appku**. `senate_rule` je pracovní data správce a
`hearing_room` patří k fázi jednání.

**Uživatelská data se nepřenášejí vůbec.** Sync se týká jen soudních dat;
`user`, `favorite` a `favorite_group` zůstávají nedotčené na obou stranách.

## Provoz

- Export se dělí na části, protože příjemce soubor nahrává formulářem a
  hostingy mají nízký limit uploadu. Části jsou id rozsahy, ne offsety — slice
  zůstane stejný, i když mezitím přibudou řádky. Každá část je samostatný úplný
  soubor (vlastní `meta` i `codelists`).
- Dev limity uploadu zvedá `docker/php/devstack.ini` (mountuje se do conf.d).
  Na produkci limit nastavit nejde, proto se velikost řídí velikostí částí.
- Doba běhu: ~13 tis. spisů zhruba **minutu**, 5 tis. jednání ~**17 s**
  (dělení na části tedy krotí i timeouty hostingu, nejen velikost uploadu).
- Komprese je u jednání extrémní (27:1) — část 5 tis. jednání má 4,4 MB
  nezabaleně a **160 kB** po gzipu, celý číselník síní 20 kB.
- **Pořadí nahrávání:** síně před jednáními a spisy před jednáními (kvůli
  `room_id` a vazbě na spis). Když to vyjde naopak, nic se nerozbije — jednání
  se uloží s prázdným odkazem a doplní se: import síní dopáruje `room_id`
  zpětně sám (`HearingRepository::linkRoom`), vazba na spis doskočí při
  opakovaném nahrání části jednání.
- **Před importem zálohovat DB** — platí stejné pravidlo jako pro datové
  migrace.
