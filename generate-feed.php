<?php
declare(strict_types=1);

/**
 * generate-feed.php
 * -----------------
 * Egy Árukereső CSV feedből külön XML feedet készít, amely CSAK a
 * Mapei és Soudal termékeket tartalmazza.
 *
 * Függőségek: TISZTA PHP CLI. Nincs Composer / külső csomag.
 *   Ajánlott kiterjesztések (PHP alaphoz tartoznak): ext-xmlwriter, ext-curl
 *   (a curl opcionális: ha nincs, file_get_contents() veszi át).
 *
 * A forrás URL NINCS a kódban: a FEED_SOURCE_URL környezeti változóból
 * (CI-ben titokból/secret-ből) vagy a --url kapcsolóból jön.
 *
 * Használat:
 *   Éles (a forrás URL a FEED_SOURCE_URL env-ből vagy --url-ből):
 *     FEED_SOURCE_URL="https://..." php generate-feed.php
 *     php generate-feed.php --url="https://..."
 *
 *   Helyi teszt (saját forrás CSV és kimenet):
 *     php generate-feed.php --source=sajat-feed.csv \
 *                           --output=output/arukereso-feed-soudal-mapei.xml
 *
 *   Súgó:
 *     php generate-feed.php --help
 *
 * Kilépési kódok: 0 = siker, !=0 = hiba (a régi kimenet ilyenkor érintetlen marad).
 */

// ---------------------------------------------------------------------------
// Konfiguráció (alapértékek; CLI kapcsolókkal felülírhatók)
// ---------------------------------------------------------------------------
const DEFAULT_OUTPUT_FILE = '/var/www/html/arukereso-feed-soudal-mapei.xml';
const FIXED_DELIVERY_COST = '1890 Ft';
const OUTPUT_CURRENCY      = 'HUF';
const HTTP_TIMEOUT_SECONDS = 60;
const USER_AGENT           = 'arukereso-feed-generator/1.0';

// A forrás CSV oszlopsorrendje (fix séma, 19 mező; ; elválasztással).
const CSV_COLUMNS = [
    'EanCode', 'Identifier', 'Manufacturer', 'Name', 'Category', 'ProductUrl',
    'Price', 'NetPrice', 'ProductNumber', 'MaxCPCMultiplier', 'DeliveryCost',
    'DeliveryTime', 'EnergyEfficiencyA-G', 'EnergyLabelA-G',
    'DetailedSpecificationA-G', 'Description', 'ImageUrl', 'ImageUrl2',
    'BasketDisabled',
];
const CSV_FIELD_COUNT = 19;

// ---------------------------------------------------------------------------
// Belépési pont — csak közvetlen futtatáskor (így a fájl tesztből include-olható)
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main($argv));
}

function main(array $argv): int
{
    $opts = getopt('', ['source:', 'output:', 'url:', 'help']);

    if (isset($opts['help'])) {
        print_usage();
        return 0;
    }

    $source = $opts['source'] ?? null;                          // helyi fájl VAGY URL
    // Forrás URL: --url kapcsoló VAGY FEED_SOURCE_URL env (CI-ben secret-ből).
    $url    = $opts['url'] ?? (getenv('FEED_SOURCE_URL') ?: '');
    $output = $opts['output'] ?? DEFAULT_OUTPUT_FILE;

    log_info(str_repeat('-', 60));
    log_info('Árukereső -> Mapei/Soudal XML feed generálás indul');

    // 1) Forrás CSV beolvasása ------------------------------------------------
    try {
        if ($source !== null) {
            // Helyi teszt: a --source lehet fájl vagy URL is.
            if (is_local_path($source)) {
                log_info("Forrás (helyi fájl): {$source}");
                $csv = read_local_file($source);
            } else {
                log_info('Forrás: URL (--source)');
                $csv = download_feed($source);
            }
        } elseif ($url !== '') {
            log_info('Forrás: URL (FEED_SOURCE_URL / --url)');
            $csv = download_feed($url);
        } else {
            log_error('Nincs forrás megadva. Adj meg --source=<fájl|URL>, --url=<URL> '
                . 'kapcsolót, vagy a FEED_SOURCE_URL környezeti változót.');
            return 1;
        }
    } catch (Throwable $e) {
        log_error('A forrás feed beolvasása/letöltése sikertelen: ' . $e->getMessage());
        return 1;
    }

    if ($csv === '' || trim($csv) === '') {
        log_error('A letöltött/beolvasott feed üres. Kilépés a régi kimenet érintése nélkül.');
        return 1;
    }

    // 2) Feldolgozás + szűrés -------------------------------------------------
    $result = build_products($csv);
    $products       = $result['products'];
    $totalRows      = $result['totalRows'];
    $backfilled     = $result['backfilled'];
    $categoryFilled = $result['categoryFilled'];
    $colorFilled    = $result['colorFilled'];
    $attrFilled     = $result['attrFilled'];
    $malformedRows  = $result['malformedRows'];

    log_info("Összes adat sor (fejléc nélkül): {$totalRows}");
    log_info('Hibás/kihagyott sorok: ' . $malformedRows);
    log_info('Megtartott Mapei/Soudal termékek: ' . count($products));
    log_info("Pótolt (terméknév alapján kiegészített) gyártók: {$backfilled}");
    log_info("Pótolt kategóriák (névből következtetve): {$categoryFilled}");
    log_info("Névből kinyert szín (<color>): {$colorFilled}");
    log_info("Névből kinyert kiszerelés (<Attributes>): {$attrFilled}");

    // 3) Védelem: 0 termék esetén NE írjuk felül a régi XML-t -----------------
    if (count($products) === 0) {
        log_error('Szűrés után 0 termék maradt -> a kimeneti fájl NEM kerül felülírásra. Kilépés.');
        return 2;
    }

    // 4) Atomi kiírás (.tmp -> rename) ---------------------------------------
    try {
        write_xml_atomic($output, $products);
    } catch (Throwable $e) {
        log_error('Az XML kiírása sikertelen: ' . $e->getMessage());
        return 3;
    }

    log_info("Kész. Kimeneti fájl: {$output}");
    log_info(str_repeat('-', 60));
    return 0;
}

// ---------------------------------------------------------------------------
// Forrás beolvasás
// ---------------------------------------------------------------------------
function is_local_path(string $source): bool
{
    return !preg_match('#^https?://#i', $source);
}

function read_local_file(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("A forrás fájl nem található vagy nem olvasható: {$path}");
    }
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException("Nem sikerült beolvasni a fájlt: {$path}");
    }
    return $data;
}

/**
 * Letölti a feedet. Előnyben részesíti a cURL-t; ha nincs, file_get_contents().
 * Hiba (nem 200, üres válasz, hálózati hiba) esetén kivételt dob.
 */
function download_feed(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_ENCODING       => '', // gzip/deflate átlátszó kezelés
            CURLOPT_FAILONERROR    => false,
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            // PHP 8.0+ alatt no-op és 8.5-ben deprecated; csak régi PHP-n hívjuk.
            curl_close($ch);
        }

        if ($errno !== 0 || $body === false) {
            throw new RuntimeException("cURL hiba ({$errno}): {$error}");
        }
        if ($status !== 0 && $status !== 200) {
            throw new RuntimeException("Váratlan HTTP státusz: {$status}");
        }
        return (string) $body;
    }

    // Fallback: file_get_contents stream kontextussal
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => HTTP_TIMEOUT_SECONDS,
            'user_agent'    => USER_AGENT,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException("file_get_contents() nem tudta letölteni: {$url}");
    }
    // HTTP státusz ellenőrzése. PHP 8.4+: http_get_last_response_headers().
    // Régebbi: a magic $http_response_header lokális változó (variable-variable
    // hozzáféréssel, hogy elkerüljük a 8.5-ös fordítási deprecation figyelmeztetést).
    $headers = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : (${'http_response_header'} ?? null);
    if (is_array($headers) && isset($headers[0]) && !preg_match('#\s200\s#', $headers[0])) {
        throw new RuntimeException('Váratlan HTTP válasz: ' . $headers[0]);
    }
    return (string) $body;
}

// ---------------------------------------------------------------------------
// CSV feldolgozás + üzleti logika
// ---------------------------------------------------------------------------
/**
 * @return array{products: array<int,array<string,string>>, totalRows:int, backfilled:int, malformedRows:int}
 */
function build_products(string $csv): array
{
    // BOM eltávolítása
    if (strncmp($csv, "\xEF\xBB\xBF", 3) === 0) {
        $csv = substr($csv, 3);
    }

    // Soronként dolgozunk (a feedben egy termék = egy fizikai sor).
    $lines = preg_split('/\r\n|\n|\r/', $csv);
    $products      = [];
    $totalRows     = 0;
    $backfilled    = 0;
    $categoryFilled = 0;
    $colorFilled   = 0;
    $attrFilled    = 0;
    $malformedRows = 0;

    $headerSeen = false;

    foreach ($lines as $line) {
        if ($line === '' ) {
            continue; // üres sor (pl. utolsó sor utáni newline)
        }

        // Fejléc kihagyása (az első nem üres sor).
        if (!$headerSeen) {
            $headerSeen = true;
            if (stripos($line, 'EanCode;') === 0) {
                continue;
            }
            // Ha nincs fejléc, ezt a sort még feldolgozzuk lentebb.
        }

        $totalRows++;
        $row = parse_csv_line($line);
        if ($row === null) {
            $malformedRows++;
            continue;
        }

        // Gyártó eldöntése (kis/nagybetű független, normalizált kimenettel).
        $manufacturerRaw = trim($row['Manufacturer']);
        $canonical = canonical_brand($manufacturerRaw);

        if ($canonical === null) {
            if ($manufacturerRaw === '') {
                // Üres gyártó: csak PREFIX alapján következtetünk a névből.
                $canonical = brand_from_name_prefix($row['Name']);
                if ($canonical !== null) {
                    $backfilled++;
                }
            }
        }

        if ($canonical === null) {
            continue; // nem Mapei/Soudal -> kihagyjuk
        }

        // Kategória: ha üres, a terméknévből következtetünk (Árukereső igényli).
        $category = $row['Category'];
        if (trim($category) === '') {
            $inferred = infer_category_from_name($row['Name'], $canonical);
            if ($inferred !== null) {
                $category = $inferred;
                $categoryFilled++;
            }
        }

        // Terméknévből kinyert extra adatok (Árukereső hivatalos mezői/attribútumai).
        $color      = extract_color_from_name($row['Name']);          // <color> mező
        $attributes = extract_attributes_from_name($row['Name']);     // <Attributes> blokk (pl. Kiszerelés)
        if ($color !== null)        $colorFilled++;
        if ($attributes !== [])     $attrFilled++;

        // Kimeneti rekord. Az ár 2 tizedesre, vesszővel (Árukereső formátum: 315,00).
        // URL/képnév/terméknév változatlan; az escaping az XML kiírásnál történik.
        $products[] = [
            'identifier'      => $row['Identifier'],
            'manufacturer'    => $canonical,
            'name'            => $row['Name'],
            'product_url'     => $row['ProductUrl'],
            'price'           => format_price($row['Price']),
            'net_price'       => format_price($row['NetPrice']),
            'currency'        => OUTPUT_CURRENCY,
            'image_url'       => $row['ImageUrl'],
            'category'        => $category,
            'description'     => $row['Description'],
            'Delivery_Time'   => $row['DeliveryTime'],
            'Delivery_Cost'   => FIXED_DELIVERY_COST,
            'EAN_code'        => $row['EanCode'],
            'color'           => $color,        // null, ha nem találtunk
            'basket_disabled' => $row['BasketDisabled'],
            '_attributes'     => $attributes,   // [[név, érték], ...]
        ];
    }

    return [
        'products'       => $products,
        'totalRows'      => $totalRows,
        'backfilled'     => $backfilled,
        'categoryFilled' => $categoryFilled,
        'colorFilled'    => $colorFilled,
        'attrFilled'     => $attrFilled,
        'malformedRows'  => $malformedRows,
    ];
}

/**
 * Árat Árukereső-formátumra hoz: 2 tizedesjegy, VESSZŐS tizedeselválasztó,
 * ezres elválasztó nélkül (a hivatalos feed_minta_HU.xml szerint: 315,00).
 * Üres/0 bemenetnél üres stringet ad vissza (nem írunk hibás 0,00-t).
 */
function format_price(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    // A forrás egész HUF (pl. "4600"); vessző/szóköz biztonsági normalizálás.
    $normalized = str_replace([' ', ','], ['', '.'], $raw);
    if (!is_numeric($normalized)) {
        return $raw; // nem szám: hagyjuk érintetlenül (nem módosítunk)
    }
    return number_format((float) $normalized, 2, ',', '');
}

/**
 * A terméknévből kinyeri a színt, ha egyértelmű színszó szerepel benne.
 * Konzervatív: csak ismert színszavakat fogad el, szóhatárral, hogy ne legyen
 * téves találat. Visszaadja a (kisbetűs) színt, vagy null-t.
 */
function extract_color_from_name(string $name): ?string
{
    // Ismert magyar színek (köztük gyakori összetettek). Szóhatárral illesztünk.
    $colors = [
        'átlátszó', 'áttetsző', 'színtelen', 'transzparens',
        'jegesszürke', 'középszürke', 'világosszürke', 'sötétszürke', 'holdfehér',
        'törtfehér', 'krémfehér', 'antracit', 'grafit',
        'fehér', 'fekete', 'szürke', 'bézs', 'barna', 'drapp',
        'piros', 'vörös', 'kék', 'zöld', 'sárga', 'narancssárga', 'narancs',
        'lila', 'rózsaszín', 'arany', 'ezüst', 'bordó', 'türkiz', 'krém',
    ];
    foreach ($colors as $c) {
        if (preg_match('/(?<![\p{L}])' . preg_quote($c, '/') . '(?![\p{L}])/iu', $name)) {
            return $c;
        }
    }
    return null;
}

/**
 * A terméknévből kinyer extra attribútumokat (jelenleg: Kiszerelés).
 * A kiszerelés egy mennyiség+mértékegység (pl. "25 KG", "310 ML", "0,25 L",
 * "70 MM × 30 M", "200 DB/CSOMAG"). Az UTOLSÓ ilyen találatot vesszük, mert a
 * kiszerelés jellemzően a név végén áll (a cikkszám előtt).
 *
 * @return array<int,array{0:string,1:string}>  [[attr_név, attr_érték], ...]
 */
function extract_attributes_from_name(string $name): array
{
    $attrs = [];

    // Mértékegység (m2/m² a sima "m" ELŐTT, hogy ne rövidüljön le).
    $unit = '(?:kg|g|ml|l|m2|m²|cm|mm|m|db(?:\s*\/\s*csomag)?)';
    $num  = '\d+(?:[.,]\d+)?';
    // Három forma, leghosszabbtól a legrövidebbig (az alternáció sorrendje számít):
    //  a) "70 mm × 30 m"  – mindkét számhoz tartozik mértékegység
    //  b) "120 × 250 mm"  – csak a végén van mértékegység
    //  c) "25 kg"         – egyszerű mennyiség
    $dimA   = $num . '\s*' . $unit . '\s*×\s*' . $num . '\s*' . $unit;
    $dimB   = $num . '\s*×\s*' . $num . '\s*' . $unit;
    $simple = $num . '\s*' . $unit;
    $pattern = '/\b(?:' . $dimA . '|' . $dimB . '|' . $simple . ')/iu';

    // Az UTOLSÓ találatot vesszük: a kiszerelés jellemzően a név végén (a cikkszám előtt) áll.
    if (preg_match_all($pattern, $name, $m) && !empty($m[0])) {
        $pack = preg_replace('/\s+/', ' ', trim(end($m[0])));
        $attrs[] = ['Kiszerelés', $pack];
    }

    return $attrs;
}

/**
 * Üres kategória esetén a terméknévből következtet kategóriára.
 * A szabályok a forrásfeed tényleges taxonómiájára épülnek, specifikus ->
 * általános sorrendben (az első találat nyer). Ha egyik kulcsszó sem illik,
 * gyártó-szintű, felső kategóriával tér vissza, hogy ne maradjon üres.
 *
 * @return string|null  null csak akkor, ha a név üres
 */
function infer_category_from_name(string $name, string $manufacturer): ?string
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    // [kulcsszó-regex (/iu), kategória] — specifikustól az általánosig.
    // Az első találat nyer, ezért a SORREND számít.
    $rules = [
        ['/ablakbeép|\bral\s*szalag\b|\bszalag\b|takarófólia|takarofolia/iu',
            'Szerszámok, barkácsáru, csiszolás > Takarófóliák, ragasztószalagok, Fiba háló'],
        ['/purhab|ragasztóhab|pisztolyhab|pur\s*hab/iu',
            'Purhabok, szilikonok, fugázók, akril tömítők > Purhabok, ragasztóhabok'],
        ['/akril\s*tömítő|akriltömítő/iu',
            'Purhabok, szilikonok, fugázók, akril tömítők > Festhető akril tömítők'],
        ['/ecetsav.*szilikon|szaniter\s*szilikon/iu',
            'Purhabok, szilikonok, fugázók, akril tömítők > Ecetsavas szilikonok'],
        // Vakolat a szilikon ELŐTT (pl. "szilikongyanta vakolat" -> vakolat, nem szilikon).
        ['/nemesvakolat|színezővakolat|szinezovakolat|kapart\s*vakolat|dörzsölt\s*vakolat|lábazati|labazati|tonachino|silancolor\s*tonachino|silexcolor|quarzolite/iu',
            'Festékek, vakolatok, bevonatok > Nemesvakolatok, színezővakolat, lábazati anyag'],
        ['/penészgát|penészöl|penészmentes|penész\s*ellen|gombaöl/iu',
            'Festékek, vakolatok, bevonatok > Mélyalapozó, vakolatalapozó, penészgátlás'],
        ['/homlokzatfest|homlokzati\s*fest|betonfest|kültéri\s*fest/iu',
            'Festékek, vakolatok, bevonatok > Kültéri homlokzatfestékek, betonfestékek'],
        ['/falfest|beltéri\s*fest|diszperzi|díszperzi/iu',
            'Festékek, vakolatok, bevonatok > Beltéri fehér falfestékek'],
        ['/\bszilikon\b/iu',
            'Purhabok, szilikonok, fugázók, akril tömítők > Semleges neutrális szilikonok'],
        ['/epoxi\s*fugáz|fugáz|fuga(?!s)/iu',
            'Purhabok, szilikonok, fugázók, akril tömítők > Fugázók, epoxi fugázók'],
        ['/csemperagaszt/iu',
            'Glett, gipsz, csemperagasztó, vakolatok > Csemperagasztók'],
        ['/\bglett\b/iu',
            'Glett, gipsz, csemperagasztó, vakolatok > Vödrös készglettek'],
        // Vízszigetelés/injektálás a tapadóhíd-alapozó ELŐTT (a "MAPESTOP nedvesség elleni
        // injektálószer" inkább vízszigetelő, mint alapozó).
        ['/injektál|nedvesség\s*ellen|vízzáró|vizzaro|vízszigetel|vizszigetel|geotextíl|geotextil|fólia\b|membrán|bitumen/iu',
            'Építőanyag, szárazépítészet, élvédők > Vízszigetelő anyagok, lemezek'],
        ['/primer|alapoz|tapadásfokoz|tapadóhíd|tapadohid|mélyalapoz|kellősít|kellosit/iu',
            'Glett, gipsz, csemperagasztó, vakolatok > Tapadóhidak, mélyalapozók, burkolási segédanyagok'],
        ['/\bspray\b/iu',
            'Festékek, vakolatok, bevonatok > Spray festékek'],
        ['/szerelőragaszt|ragaszt|tömítő/iu',
            'Purhabok, szilikonok, fugázók, akril tömítők > Speciális ragasztók, tömítők'],
    ];

    foreach ($rules as [$pattern, $category]) {
        if (preg_match($pattern, $name)) {
            return $category;
        }
    }

    // Fallback: gyártó-szintű felső kategória, hogy ne legyen üres kategória.
    $brandFallback = [
        'Mapei'  => 'Glett, gipsz, csemperagasztó, vakolatok',
        'Soudal' => 'Purhabok, szilikonok, fugázók, akril tömítők',
    ];
    return $brandFallback[$manufacturer] ?? 'Egyéb';
}

/**
 * Egy CSV sor mezőkre bontása fix sémával.
 * A mezők NINCSENEK idézőjelezve, és a Description tartalmazhat ; jelet, ezért
 * BAL 15 + JOBB 3 mezőt lehorgonyzunk, a köztes rész = Description.
 *
 * @return array<string,string>|null  null, ha a sor hibás (túl kevés mező)
 */
function parse_csv_line(string $line): ?array
{
    $line  = rtrim($line, "\r\n");
    $parts = explode(';', $line);
    $n     = count($parts);

    if ($n < CSV_FIELD_COUNT) {
        return null; // hiányos sor
    }

    if ($n === CSV_FIELD_COUNT) {
        return array_combine(CSV_COLUMNS, $parts);
    }

    // n > 19: a többlet pontosvessző(k) a Description-ben vannak.
    // Bal 15 mező (index 0..14), jobb 3 mező (utolsó 3), köztük = Description.
    $left        = array_slice($parts, 0, 15);
    $right       = array_slice($parts, $n - 3, 3);
    $description = implode(';', array_slice($parts, 15, $n - 18));

    $values = array_merge($left, [$description], $right); // 15 + 1 + 3 = 19
    return array_combine(CSV_COLUMNS, $values);
}

/**
 * A megadott (nem üres) gyártónevet Mapei/Soudal kanonikus alakra hozza.
 * @return string|null  'Mapei' | 'Soudal' | null (ha nem a két márka egyike)
 */
function canonical_brand(string $manufacturer): ?string
{
    if ($manufacturer === '') {
        return null;
    }
    if (preg_match('/^mapei$/iu', $manufacturer)) {
        return 'Mapei';
    }
    if (preg_match('/^soudal$/iu', $manufacturer)) {
        return 'Soudal';
    }
    return null;
}

/**
 * Üres gyártó esetén a terméknév ELEJÉRŐL következtet (szóhatárral),
 * hogy a név közepén előforduló "mapei"/"soudal" ne adjon téves találatot.
 * @return string|null
 */
function brand_from_name_prefix(string $name): ?string
{
    $name = ltrim($name);
    if (preg_match('/^mapei\b/iu', $name)) {
        return 'Mapei';
    }
    if (preg_match('/^soudal\b/iu', $name)) {
        return 'Soudal';
    }
    return null;
}

// ---------------------------------------------------------------------------
// XML kiírás (atomi: .tmp -> rename)
// ---------------------------------------------------------------------------
/**
 * @param array<int,array<string,string>> $products
 */
function write_xml_atomic(string $outputPath, array $products): void
{
    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("A kimeneti könyvtár nem hozható létre: {$dir}");
        }
    }
    if (!is_writable($dir)) {
        throw new RuntimeException("A kimeneti könyvtár nem írható: {$dir}");
    }

    // Egyedi tmp fájl ugyanabban a könyvtárban (a rename így atomi marad).
    $tmpPath = $outputPath . '.tmp.' . getmypid();

    $writer = new XMLWriter();
    if ($writer->openUri($tmpPath) === false) {
        throw new RuntimeException("Nem sikerült megnyitni írásra: {$tmpPath}");
    }
    $writer->setIndent(true);
    $writer->setIndentString('  ');
    $writer->startDocument('1.0', 'UTF-8');
    $writer->writeComment(' Generálva: ' . date('c') . ' | Szűrés: Mapei, Soudal ');
    $writer->startElement('products');

    foreach ($products as $p) {
        // Az attribútum-blokkot külön kezeljük; a 'color' opcionális (üresen kihagyjuk).
        $attributes = $p['_attributes'] ?? [];
        unset($p['_attributes']);

        $writer->startElement('product');
        foreach ($p as $tag => $value) {
            // Opcionális mezőket (null/üres color) nem írunk ki.
            if ($value === null || $value === '') {
                if ($tag === 'color') {
                    continue;
                }
            }
            // writeElement gondoskodik a helyes XML-escapelésről.
            $writer->writeElement($tag, (string) $value);
        }

        // <Attributes> blokk (Árukereső hivatalos szerkezet), ha van mit.
        if ($attributes !== []) {
            $writer->startElement('Attributes');
            foreach ($attributes as [$attrName, $attrValue]) {
                $writer->startElement('Attribute');
                $writer->writeElement('Attribute_name', $attrName);
                $writer->writeElement('Attribute_value', $attrValue);
                $writer->endElement(); // Attribute
            }
            $writer->endElement(); // Attributes
        }

        $writer->endElement(); // product
    }

    $writer->endElement();   // products
    $writer->endDocument();
    $writer->flush();
    unset($writer); // lezárja a fájlkezelőt

    if (!is_file($tmpPath)) {
        throw new RuntimeException("A tmp fájl nem jött létre: {$tmpPath}");
    }

    if (!@rename($tmpPath, $outputPath)) {
        @unlink($tmpPath);
        throw new RuntimeException("A rename sikertelen: {$tmpPath} -> {$outputPath}");
    }
    @chmod($outputPath, 0644);
}

// ---------------------------------------------------------------------------
// Logolás & súgó
// ---------------------------------------------------------------------------
function log_info(string $msg): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [INFO]  ' . $msg . PHP_EOL);
}

function log_error(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] [ERROR] ' . $msg . PHP_EOL);
}

function print_usage(): void
{
    $help = <<<TXT
Árukereső -> Mapei/Soudal XML feed generátor

Használat:
  php generate-feed.php [--source=<fájl|URL>] [--url=<URL>] [--output=<fájl>]

A forrás URL a FEED_SOURCE_URL környezeti változóból (CI-ben secret-ből) vagy
a --url kapcsolóból jön; a kódban nincs bedrótozva.

Kapcsolók:
  --source=<fájl|URL>  Forrás CSV. Lehet helyi fájl vagy URL (helyi teszthez).
  --url=<URL>          Éles forrás URL (felülírja a FEED_SOURCE_URL env-et).
  --output=<fájl>      Kimeneti XML útvonal
                       (alap: /var/www/html/arukereso-feed-soudal-mapei.xml).
  --help               Ez a súgó.

Példák:
  FEED_SOURCE_URL="https://..." php generate-feed.php
  php generate-feed.php --url="https://..."
  php generate-feed.php --source=sajat-feed.csv \
                        --output=output/arukereso-feed-soudal-mapei.xml

TXT;
    fwrite(STDOUT, $help . PHP_EOL);
}
