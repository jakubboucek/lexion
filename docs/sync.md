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
   `court_prefix`, `relation_type`) pro porovnání. Dřív to byl jediný řádek
   se vším, což dělalo ~38 kB dlouhou nečitelnou řádku.
3. první záznam, který není `codelist`, začíná data — dnes `case_file`
   záznamy, každý **včetně svých událostí a vazeb**.

Vnořením událostí a vazeb do spisu obě strany pracují v konstantní paměti:
čtenář má po jednom řádku vše, co k rozhodnutí o spisu potřebuje, a může ho
zahodit dřív, než načte další.

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

**Číselníky musí sednout přesně.** Liší-li se jediný řádek nebo sloupec, import
se zastaví ještě před prvním zápisem a nic nezmění. Je to záměrně fail-closed:
nemáme odpověď na to, co znamená změna číselníku pro data, která na něm visí
(zmizelá síň, ke které jsou navázaná jednání), takže rozdíl znamená, že se
prostředí rozešla a musí se srovnat migrací. Porovnávají se jen číselníky, na
které data spisů odkazují; `senate_rule` je pracovní data správce a
`hearing_room` patří k fázi jednání. Číselníky se čtou z cachovaného snapshotu,
tedy přesně z toho, co používá aplikace — **ruční číselníková migrace bez
smazání cache je pro sync neviditelná stejně jako pro appku**.

**Uživatelská data se nepřenášejí vůbec.** Sync se týká jen soudních dat;
`user`, `favorite` a `favorite_group` zůstávají nedotčené na obou stranách.

## Provoz

- Export se dělí na části, protože příjemce soubor nahrává formulářem a
  hostingy mají nízký limit uploadu. Části jsou id rozsahy, ne offsety — slice
  zůstane stejný, i když mezitím přibudou řádky. Každá část je samostatný úplný
  soubor (vlastní `meta` i `codelists`).
- Dev limity uploadu zvedá `docker/php/devstack.ini` (mountuje se do conf.d).
  Na produkci limit nastavit nejde, proto se velikost řídí velikostí částí.
- Plný import ~13 tis. spisů trvá zhruba **minutu** (3 dotazy na spis);
  dělení na části zároveň krotí dobu běhu proti timeoutům hostingu.
- **Před importem zálohovat DB** — platí stejné pravidlo jako pro datové
  migrace.
