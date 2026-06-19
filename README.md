# Árukereső → Mapei/Soudal XML feed

PHP CLI script, amely egy Árukereső CSV feedből (`;` elválasztású) külön XML feedet
készít, **kizárólag a Mapei és Soudal termékekkel**, az Árukereső hivatalos formátumában.

- Nincs Composer / külső csomag — **tiszta PHP CLI** (`ext-xmlwriter`, opcionálisan `ext-curl`).
- Robusztus CSV parse: a `Description` mezőben előforduló `;` jeleket helyesen kezeli.
- Atomi kiírás: előbb `.tmp`, majd `rename` — az olvasók sosem látnak fél fájlt.
- Hibatűrő: letöltési hiba vagy 0 találat esetén a **régi XML érintetlen marad**.
- A forrás URL **nincs a kódban** — a `FEED_SOURCE_URL` env / secret vagy a `--url` adja.

## Követelmények

- PHP 7.4+ (tesztelve PHP 8.5-tel). `ext-curl` ajánlott, de nem kötelező.

## Használat

A forrás URL-t környezeti változóból vagy kapcsolóból kapja:

```bash
FEED_SOURCE_URL="https://<forrás-feed-url>" php generate-feed.php --output=feed.xml
# vagy
php generate-feed.php --url="https://<forrás-feed-url>" --output=feed.xml
```

Helyi teszt saját CSV fájllal (nincs hálózat):

```bash
php generate-feed.php \
  --source=sajat-feed.csv \
  --output=output/arukereso-feed-soudal-mapei.xml
```

Súgó: `php generate-feed.php --help`

### Kapcsolók

| Kapcsoló | Leírás |
|---|---|
| `--source=<fájl\|URL>` | Forrás CSV (helyi teszthez fájl vagy URL is lehet) |
| `--url=<URL>` | Éles forrás URL (felülírja a `FEED_SOURCE_URL` env-et) |
| `--output=<fájl>` | Kimeneti XML útvonal |

## Üzleti logika

1. **Szűrés:** csak az a termék marad, ahol a `Manufacturer` (kis/nagybetűtől függetlenül) `mapei` vagy `soudal`. Az XML-ben egységesen `Mapei` / `Soudal` szerepel.
2. **Gyártó pótlása:** ha a `Manufacturer` üres, a `Name` **eleje** alapján következtet (szóhatárral).
3. **Fix szállítási díj:** minden terméknél `<Delivery_Cost>1890 Ft</Delivery_Cost>`.
4. **Kategória pótlása:** ha a `Category` üres, a script a terméknévből következtet kategóriára. Lásd `infer_category_from_name()`.
5. **Ár:** `price` és `net_price` 2 tizedesre, vesszős elválasztóval (Árukereső formátum: `4600,00`). Lásd `format_price()`.
6. **Névből kinyert adatok:** `<color>` (szín) és `<Attributes>` / Kiszerelés (pl. `25 KG`, `70 MM × 30 M`). Lásd `extract_color_from_name()`, `extract_attributes_from_name()`.
7. Az URL-eket, képneveket, termékneveket **nem módosítja**, csak XML-escape-eli.

A kimeneti XML szerkezet az Árukereső hivatalos mintáját követi (`feed_minta_HU.xml`).

## Kilépési kódok

| Kód | Jelentés |
|---|---|
| 0 | Siker, az XML frissült |
| 1 | Forrás hiányzik vagy letöltési/olvasási hiba (régi XML érintetlen) |
| 2 | Szűrés után 0 termék (régi XML érintetlen) |
| 3 | XML kiírási hiba |

## Közzététel: GitHub Actions + GitHub Pages (ingyenes)

A `.github/workflows/feed.yml` naponta legenerálja a feedet és kiteszi GitHub Pages-re.
Publikus repónál az Actions és a Pages is ingyenes.

### Egyszeri beállítás

1. **Forrás URL titokként:**
   **Settings → Secrets and variables → Actions → New repository secret**
   - Név: `FEED_SOURCE_URL`
   - Érték: a forrás CSV feed teljes URL-je
   (Így a forrás URL nem kerül a publikus kódba/logba.)

2. **Pages bekapcsolása:**
   **Settings → Pages → Build and deployment → Source: „GitHub Actions"**.

3. **Első futtatás / teszt:**
   **Actions** fül → a workflow → **Run workflow** (kézi indítás).

4. A feed URL-je:
   ```
   https://<felhasznalo>.github.io/<repo>/arukereso-feed-soudal-mapei.xml
   ```
   Ezt az URL-t add meg az Árukereső bolti felületén.

### A cron (napi automatikus futás)

- Az ütemezés a workflow-ban van: `cron: '10 3 * * *'` (**UTC** szerint — kb. 05:10 nyári / 04:10 téli idő itthon). A perc/óra a `feed.yml`-ben szabadon átírható.
- A cron **csak az alapértelmezett ágon (`main`)** lévő workflow-ra fut, és csak akkor indul el magától, ha a `feed.yml` már a `main`-en van (push után).
- Külön „elindítani" nem kell: a beállítás után magától fut a megadott időben. A `workflow_dispatch` a kézi tesztindításhoz van.
- **Fontos:** a GitHub **60 nap repo-inaktivitás után letiltja** az ütemezett workflow-t. Egy commit vagy egy kézi indítás újraaktiválja. (Aktív használatnál ez nem gond.)
- Az ütemezett futás csúcsidőben késhet pár-tíz percet — napi 1 feednél lényegtelen.

## Alternatíva: saját szerver

Saját (al)domain alatti kiszolgáláshoz (Ubuntu + nginx) a lépéssor és a cron a
[TODO.md](TODO.md)-ben van.
