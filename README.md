# nett.hu Árukereső → Mapei/Soudal XML feed

PHP CLI script, amely a nett.hu Árukereső CSV feedjéből (`;` elválasztású)
külön XML feedet készít, **kizárólag a Mapei és Soudal termékekkel**.

- Nincs Composer / külső csomag — **tiszta PHP CLI** (`ext-xmlwriter`, opcionálisan `ext-curl`, mindkettő a PHP alaptelepítés része).
- Robusztus CSV parse: a `Description` mezőben előforduló `;` jeleket helyesen kezeli (bal 15 + jobb 3 mező lehorgonyzás).
- Atomi kiírás: előbb `.tmp`, majd `rename` — az olvasók sosem látnak fél fájlt.
- Hibatűrő: letöltési hiba vagy 0 találat esetén a **régi XML érintetlen marad**.

## Követelmények

- PHP 7.4+ (tesztelve PHP 8.5-tel). `ext-curl` ajánlott, de nem kötelező.

## Használat

Éles mód (URL-ről tölt, alap kimenet a VPS publikus mappába):

```bash
php generate-feed.php
```

Helyi teszt (saját forrás + kimenet):

```bash
php generate-feed.php \
  --source=examples/ArukeresoFeed_sample.csv \
  --output=output/arukereso-feed-soudal-mapei.xml
```

Súgó:

```bash
php generate-feed.php --help
```

### Kapcsolók

| Kapcsoló | Leírás | Alapérték |
|---|---|---|
| `--source=<fájl\|URL>` | Forrás CSV (helyi teszthez fájl vagy URL is lehet) | a `--url` |
| `--url=<URL>` | Éles forrás URL | `https://nett.hu/arukereso-feed` |
| `--output=<fájl>` | Kimeneti XML útvonal | `/var/www/html/arukereso-feed-soudal-mapei.xml` |

## Üzleti logika

1. **Szűrés:** csak az a termék marad, ahol a `Manufacturer` (kis/nagybetűtől függetlenül) `mapei` vagy `soudal`. Az XML-ben egységesen `Mapei` / `Soudal` szerepel (pl. a forrásbeli `SOUDAL` → `Soudal`).
2. **Gyártó pótlása:** ha a `Manufacturer` üres, a `Name` **eleje** alapján következtet (szóhatárral), hogy a név közepén lévő „mapei"/„soudal" ne adjon téves találatot.
3. **Fix szállítási díj:** minden terméknél `<Delivery_Cost>1890 Ft</Delivery_Cost>`.
4. **Kategória pótlása:** ha a `Category` üres, a script a **terméknévből** következtet kategóriára (kulcsszó-alapú szabályok a nett.hu valós taxonómiájára építve, gyártó-szintű fallbackkel), így nem marad üres kategóriájú termék. Lásd `infer_category_from_name()`.
5. Az URL-eket, képneveket, termékneveket **nem módosítja**, csak XML-escape-eli. (A kép `image_url` ott marad üresen, ahol a forrásban sincs kép — ez szándékos.)

## Kimeneti XML szerkezet

```xml
<?xml version="1.0" encoding="UTF-8"?>
<products>
  <product>
    <identifier>…</identifier>
    <manufacturer>Mapei|Soudal</manufacturer>
    <name>…</name>
    <product_url>…</product_url>
    <price>…</price>
    <net_price>…</net_price>
    <currency>HUF</currency>
    <image_url>…</image_url>
    <category>…</category>
    <description>…</description>
    <Delivery_Time>…</Delivery_Time>
    <Delivery_Cost>1890 Ft</Delivery_Cost>
    <EAN_code>…</EAN_code>
    <basket_disabled>0|1</basket_disabled>
  </product>
  …
</products>
```

## Kilépési kódok

| Kód | Jelentés |
|---|---|
| 0 | Siker, az XML frissült |
| 1 | Forrás letöltési/olvasási hiba (régi XML érintetlen) |
| 2 | Szűrés után 0 termék (régi XML érintetlen) |
| 3 | XML kiírási hiba |

## VPS telepítés (TODO – a cront a VPS-en kell beállítani)

A scriptet a kívánt publikus fájlt termelő mappa mellé érdemes tenni, pl. `/var/www/nett-feed/generate-feed.php`,
a kimenet pedig a webgyökérbe kerül:

- Fájl: `/var/www/html/arukereso-feed-soudal-mapei.xml`
- URL:  `https://nett.hu/arukereso-feed-soudal-mapei.xml`

Lásd: [TODO.md](TODO.md) a cron beállításához.
# Nett_XML_feed
