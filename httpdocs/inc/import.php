<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/*
 * Recept importeren van een link.
 *
 * Bijna elke receptensite (Allerhande, Leukerecepten, 24Kitchen, ...) zet
 * het recept als schema.org Recipe in JSON-LD op de pagina, voor Google.
 * Dat lezen we: naam, personen, ingrediëntregels en bereiding. De
 * ingrediëntregels ("250 g kastanjechampignons, in plakjes") worden
 * ontleed en gekoppeld aan onze eigen ingrediëntenlijst; wat je in het
 * koppelvenster kiest wordt onthouden in {ingredient_alias}, zodat de
 * volgende import het meteen goed heeft.
 */

// Eenheid zoals sites hem schrijven => [onze eenheid, vermenigvuldiger].
const IMPORT_UNITS = [
    'g' => ['g', 1], 'gr' => ['g', 1], 'gram' => ['g', 1], 'grams' => ['g', 1],
    'kg' => ['kg', 1], 'kilo' => ['kg', 1], 'kilogram' => ['kg', 1],
    'ml' => ['ml', 1], 'cl' => ['ml', 10], 'dl' => ['ml', 100],
    'l' => ['l', 1], 'liter' => ['l', 1], 'litre' => ['l', 1],
    'el' => ['el', 1], 'eetl' => ['el', 1], 'eetlepel' => ['el', 1], 'eetlepels' => ['el', 1], 'tbsp' => ['el', 1],
    'tl' => ['tl', 1], 'theel' => ['tl', 1], 'theelepel' => ['tl', 1], 'theelepels' => ['tl', 1], 'tsp' => ['tl', 1],
    'teen' => ['teen', 1], 'teentje' => ['teen', 1], 'teentjes' => ['teen', 1], 'tenen' => ['teen', 1],
    'blik' => ['blik', 1], 'blikje' => ['blik', 1], 'blikjes' => ['blik', 1], 'blikken' => ['blik', 1],
    'pak' => ['pak', 1], 'pakje' => ['pak', 1], 'pakjes' => ['pak', 1], 'pakken' => ['pak', 1],
    'pot' => ['pot', 1], 'potje' => ['pot', 1], 'potjes' => ['pot', 1], 'potten' => ['pot', 1],
    'bos' => ['bosje', 1], 'bosje' => ['bosje', 1], 'bosjes' => ['bosje', 1],
    'snuf' => ['snuf', 1], 'snufje' => ['snuf', 1], 'snufjes' => ['snuf', 1],
    'plak' => ['plak', 1], 'plakje' => ['plak', 1], 'plakjes' => ['plak', 1], 'plakken' => ['plak', 1],
    'zak' => ['zak', 1], 'zakje' => ['zak', 1], 'zakjes' => ['zak', 1], 'zakken' => ['zak', 1],
    'stuk' => [null, 1], 'stuks' => [null, 1], 'krop' => [null, 1], 'kropje' => [null, 1],
    'stengel' => [null, 1], 'stengels' => [null, 1],
];

// Hoeveelheden waar je op de boodschappenlijst niets aan hebt: "1 scheut
// melk" is geen "1 melk". Die regels komen zonder hoeveelheid mee.
const IMPORT_VAGUE_UNITS = ['scheut', 'scheutje', 'scheutjes', 'handje', 'handjes', 'handjevol', 'handvol',
                            'takje', 'takjes', 'beetje', 'mespunt', 'mespuntje', 'cup', 'cups', 'klontje', 'klont'];

// Bijvoeglijke woorden die niets zeggen over wát je koopt. Weg uit de naam
// van een nieuw ingrediënt, en tellen niet mee bij het koppelen.
const IMPORT_FILLER_WORDS = ['verse', 'vers', 'grote', 'groot', 'kleine', 'klein', 'middelgrote', 'flinke',
                             'fijngesneden', 'fijngehakte', 'fijngesnipperde', 'gesnipperde', 'gesneden',
                             'gehakte', 'geraspte', 'gepelde', 'uitgelekte', 'ontdooide', 'bevroren',
                             'biologische', 'gedroogde', 'gedroogd', 'ongeveer', 'circa', 'ca', 'extra', 'naar', 'smaak'];

// Andere naam voor wat wij als één ingrediënt kennen (stam => onze woorden).
// Klein houden: de rest leert hij van je keuzes in het koppelvenster.
const IMPORT_SYNONYMS = [
    'spaghetti' => 'pasta', 'penne' => 'pasta', 'fusilli' => 'pasta', 'farfalle' => 'pasta',
    'tagliatelle' => 'pasta', 'linguine' => 'pasta', 'rigatoni' => 'pasta', 'orzo' => 'pasta',
    'bouillon' => 'bouillonblokje', 'bouillontablet' => 'bouillonblokje',
    'kippenbouillon' => 'bouillonblokje', 'groentebouillon' => 'bouillonblokje',
    'mienest' => 'mie', 'eiermie' => 'mie', 'kerrie' => 'kerriepoeder', 'kip' => 'kipfilet',
];

// Vorm waarin je iets koopt, als staart van een samenstelling:
// "kipfiletreepjes" is kipfilet, "paprikapoeder" is geen paprika.
const IMPORT_FORM_TAILS = ['reep', 'rep', 'blok', 'stuk', 'plak', 'schijf', 'part', 'snipper', 'ring'];

// Blijft zonder koppeling standaard buiten het recept: dat heeft iedereen.
const IMPORT_SKIP_WORDS = ['zout', 'peper', 'water', 'olie', 'olijfolie', 'zonnebloemolie', 'arachideolie',
                           'bakolie', 'zout en peper', 'peper en zout', 'peper zout', 'ijsblokjes'];

/** Haalt de pagina op. Null als het niet lukt. */
function importFetch(string $url): ?string
{
    if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_ENCODING       => '',
        // Veel sites weigeren een kale curl; doe je voor als een browser.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/128.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: nl,en;q=0.8'],
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return is_string($body) && $status === 200 && strlen($body) < 5_000_000 ? $body : null;
}

/** Zoekt het Recipe-object in de JSON-LD blokken van een pagina. */
function importFindRecipe(string $html): ?array
{
    preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $m);

    foreach ($m[1] as $json) {
        $data = json_decode(trim($json), true);
        if (!is_array($data)) {
            // Sommige sites zetten er rauwe regeleinden in strings in.
            $data = json_decode(preg_replace('/[\r\n\t]+/', ' ', trim($json)) ?? '', true);
        }
        if (is_array($data) && ($recipe = importWalk($data)) !== null) {
            return $recipe;
        }
    }

    return null;
}

/** Doorzoekt een JSON-LD boom (ook @graph en lijsten) naar @type Recipe. */
function importWalk(array $node): ?array
{
    $type = $node['@type'] ?? null;
    if ($type === 'Recipe' || (is_array($type) && in_array('Recipe', $type, true))) {
        return $node;
    }
    foreach ($node as $child) {
        if (is_array($child) && ($found = importWalk($child)) !== null) {
            return $found;
        }
    }

    return null;
}

function importText(mixed $value): string
{
    if (is_array($value)) {
        $value = $value['text'] ?? $value['name'] ?? reset($value);
    }
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** Bereiding: tekst, lijst tekst, HowToStep of HowToSection. Plat naar regels. */
function importSteps(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('~<br\s*/?>|</p>|</li>|\r\n|\n|\r~i', $value) ?: [];
    }
    if (!is_array($value)) {
        return [];
    }
    if (isset($value['@type'])) {
        $value = [$value];
    }

    $steps = [];
    foreach ($value as $step) {
        if (is_array($step) && isset($step['itemListElement'])) {
            $steps = array_merge($steps, importSteps($step['itemListElement']));
            continue;
        }
        $text = importText($step);
        if ($text !== '') {
            $steps[] = $text;
        }
    }

    // Eén lange lap tekst (24Kitchen doet dat): per zin een stap.
    if (count($steps) === 1 && mb_strlen($steps[0]) > 200) {
        $steps = preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', $steps[0]) ?: $steps;
    }

    return $steps;
}

/** ISO 8601 duur ("PT1H15M") in minuten. */
function importMinutes(mixed $value): int
{
    if (!is_string($value) || !preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?/i', $value, $m)) {
        return 0;
    }
    return (int)($m[1] ?? 0) * 1440 + (int)($m[2] ?? 0) * 60 + (int)($m[3] ?? 0);
}

/** Soort gokken uit de naam; overig als niets past. */
function importGuessCategory(string $name): string
{
    $name = mb_strtolower($name);
    $map  = [
        'stamppot' => 'stamppot', 'soep' => 'soep', 'ovenschotel' => 'oven', 'lasagne' => 'oven',
        'uit de oven' => 'oven', 'pizza' => 'oven', 'wrap' => 'wraps', 'pita' => 'wraps', 'burger' => 'wraps',
        'tortilla' => 'wraps', 'bami' => 'noedels', 'mie' => 'noedels', 'noedel' => 'noedels',
        'nasi' => 'rijst', 'rijst' => 'rijst', 'risotto' => 'rijst', 'curry' => 'rijst',
        'pasta' => 'pasta', 'spaghetti' => 'pasta', 'macaroni' => 'pasta', 'penne' => 'pasta',
        'tagliatelle' => 'pasta', 'gnocchi' => 'pasta', 'zalm' => 'vis', 'kabeljauw' => 'vis',
        'vis' => 'vis', 'garnalen' => 'vis', 'bonen' => 'bonen', 'linzen' => 'bonen',
        'kikkererwten' => 'bonen', 'aardappel' => 'aardappel', 'krieltjes' => 'aardappel',
    ];
    foreach ($map as $word => $category) {
        // Aan het begin of eind van een woord: "champignonsoep" is soep,
        // maar "mie" zit niet in "romige".
        $w = preg_quote($word, '/');
        if (preg_match('/\b' . $w . '|' . $w . '\b/u', $name)) {
            return $category;
        }
    }
    return 'overig';
}

/**
 * Ontleedt een ingrediëntregel van een site.
 * "1½ el olijfolie"            => [1.5, 'el', 'olijfolie']
 * "250 g kastanjechampignons, in plakjes" => [250, 'g', 'kastanjechampignons']
 * "1 blik tomatenblokjes (400 g)" => [1, 'blik', 'tomatenblokjes']
 * "2 dl kookroom"              => [200, 'ml', 'kookroom']
 */
function importParseLine(string $raw): array
{
    $line = mb_strtolower(importText($raw));

    // Breuken: "1½", "½", "1 1/2", "1/2".
    $fractions = ['½' => .5, '¼' => .25, '¾' => .75, '⅓' => 1 / 3, '⅔' => 2 / 3];
    $line = preg_replace_callback(
        '/(\d*)\s*([½¼¾⅓⅔])/u',
        static fn(array $m): string => (string)((int)$m[1] + $fractions[$m[2]]),
        $line
    ) ?? $line;
    $line = preg_replace_callback(
        '~(?:(\d+)\s+)?(\d+)/(\d+)~',
        static fn(array $m): string => (int)$m[3] === 0 ? $m[0] : (string)((int)$m[1] + (int)$m[2] / (int)$m[3]),
        $line
    ) ?? $line;
    $line = preg_replace('/^(een|a|an)\s+/u', '1 ', $line) ?? $line;

    $amount = null;
    $unit   = null;

    // Getal vooraan, eventueel een bereik ("2-3 uien": we nemen de eerste).
    if (preg_match('/^(\d+(?:[.,]\d+)?)(?:\s*(?:-|–|tot|à)\s*\d+(?:[.,]\d+)?)?\s*(.*)$/u', $line, $m)) {
        $amount = (float)str_replace(',', '.', $m[1]);
        $line   = $m[2];

        if (preg_match('/^([a-z]+)\.?(?:\s+|$)(.*)$/u', $line, $u)) {
            if (isset(IMPORT_UNITS[$u[1]])) {
                [$unit, $factor] = IMPORT_UNITS[$u[1]];
                $amount *= $factor;
                $line = $u[2];
            } elseif (in_array($u[1], IMPORT_VAGUE_UNITS, true)) {
                $amount = null;
                $line   = $u[2];
            }
        }
    }

    // Eenheid zonder getal: "snufje zout", "scheut melk".
    if ($amount === null && preg_match('/^([a-z]+)\s+(.*)$/u', $line, $u)
        && (isset(IMPORT_UNITS[$u[1]]) || in_array($u[1], IMPORT_VAGUE_UNITS, true))) {
        $line = $u[2];
    }

    // Wat na de naam komt is bereiding: "(400 g)", ", in plakjes", "naar smaak".
    $name = preg_replace('/\([^)]*\)/u', ' ', $line) ?? $line;
    $name = preg_split('/,|;| - | voor | om te | naar smaak/u', $name)[0] ?? $name;
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name, " .:-");

    // Gewone dl/cl-omrekening kan 200.0000001 opleveren.
    if ($amount !== null) {
        $amount = round($amount, 2);
    }

    return ['raw' => importText($raw), 'amount' => $amount, 'unit' => $unit, 'name' => $name];
}

/**
 * Grove Nederlandse stam, voor vergelijken en niet voor weergave:
 * meervoud en verkleinwoord eraf, dubbele klinkers gelijkgetrokken, zodat
 * "tomaten"/"tomaat", "worteltjes"/"wortel" en "uien"/"ui" gelijk worden.
 */
function importStem(string $word): string
{
    $irregular = ['eieren' => 'ei', 'eitje' => 'ei', 'eitjes' => 'ei', 'uitjes' => 'ui', 'uitje' => 'ui'];
    if (isset($irregular[$word])) {
        return $irregular[$word];
    }
    // Verkleinwoord: "tomaatje" is tomaat+je, "worteltje" wortel+tje. Eerst
    // alleen -je eraf; een t na l/n/r/m hoorde dan bij het achtervoegsel.
    if (mb_strlen($word) > 5 && preg_match('/^(.*?)(jes|je)$/u', $word, $m)) {
        $word = preg_replace('/([lnrm])t$/u', '$1', $m[1]) ?? $m[1];
    } elseif (mb_strlen($word) > 3) {
        $word = preg_replace('/(en|s)$/u', '', $word) ?? $word;
    }
    return preg_replace('/([aeou])\1/u', '$1', $word) ?? $word;
}

/** Woorden als stam, zonder vulwoorden. */
function importWords(string $text): array
{
    $text  = preg_replace('/[^\p{L}\s]/u', ' ', mb_strtolower($text)) ?? '';
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    $words = array_filter($words, static fn(string $w): bool =>
        mb_strlen($w) > 1 && !in_array($w, IMPORT_FILLER_WORDS, true) && !in_array($w, ['en', 'of', 'met', 'de', 'het'], true));

    $stems = [];
    foreach ($words as $w) {
        $stem  = importStem($w);
        foreach (IMPORT_SYNONYMS as $from => $to) {
            // Ook als achterste deel: "kippenbouillontablet".
            if ($stem === $from || (mb_strlen($from) >= 5 && str_ends_with($stem, $from))) {
                $stem = importStem($to);
                break;
            }
        }
        $stems[] = $stem;
    }
    return array_values(array_unique($stems));
}

/** Naam voor een nieuw ingrediënt: zonder vulwoorden, zoals je hem zelf zou typen. */
function importCleanName(string $name): string
{
    $words = preg_split('/\s+/u', $name) ?: [];
    $kept  = array_filter($words, static fn(string $w): bool => !in_array($w, IMPORT_FILLER_WORDS, true));
    return mb_substr(trim(implode(' ', $kept ?: $words)), 0, 80);
}

/**
 * Hoe goed past ingrediënt $ing (stammen) bij de woorden van een regel.
 * 0 = niet. Meer woorden die kloppen = specifieker = hoger, zodat "rode
 * ui" wint van "ui" als beide in de lijst staan.
 */
function importScore(array $ing, array $line, string $ingName, string $lineName): int
{
    if ($ing === [] || $line === []) {
        return 0;
    }
    $bonus = 5 * count($ing);

    sort($ing);
    $sortedLine = $line;
    sort($sortedLine);
    if ($ing === $sortedLine) {
        return 100 + $bonus;
    }
    if (array_diff($ing, $line) === []) {
        return 70 + $bonus;
    }

    // Samenstellingen: het laatste deel is wat het ís, dus "rundergehakt"
    // is gehakt en "kookroom" is room. Vooraan alleen met een vorm erachter
    // ("kipfiletreepjes"), want "paprikapoeder" is geen paprika.
    $hit = 0;
    foreach ($ing as $iw) {
        foreach ($line as $lw) {
            if ($iw === $lw
                || (mb_strlen($iw) >= 3 && str_ends_with($lw, $iw))
                || (mb_strlen($iw) >= 4 && str_starts_with($lw, $iw)
                    && in_array(mb_substr($lw, mb_strlen($iw)), IMPORT_FORM_TAILS, true))) {
                $hit++;
                continue 2;
            }
        }
    }
    if ($hit === count($ing)) {
        return 50 + $bonus;
    }

    // Tikfout of andere spelling: "courgete", "champions".
    similar_text($ingName, $lineName, $percent);
    return $percent >= 82 ? 40 : 0;
}

/**
 * Koppelt een ontlede regel aan onze ingrediënten.
 * $ingredients: [['id' => .., 'name' => ..]], $aliases: [alias => id|null].
 * Geeft de beste kandidaten terug en een voorstel: id, 'new' of 'skip'.
 */
function importMatch(array $parsed, array $ingredients, array $aliases): array
{
    $name = $parsed['name'];

    if (array_key_exists($name, $aliases)) {
        $id = $aliases[$name];
        $candidates = [];
        foreach ($ingredients as $ing) {
            if ((int)$ing['id'] === $id) {
                $candidates[] = ['id' => (int)$ing['id'], 'name' => $ing['name'], 'score' => 1000];
            }
        }
        return ['candidates' => $candidates, 'choice' => $id ?? 'skip', 'learned' => true];
    }

    $lineWords = importWords($name);
    $scored    = [];
    foreach ($ingredients as $ing) {
        $score = importScore(importWords($ing['name']), $lineWords, $ing['name'], $name);
        if ($score > 0) {
            $scored[] = ['id' => (int)$ing['id'], 'name' => $ing['name'], 'score' => $score];
        }
    }
    usort($scored, static fn(array $a, array $b): int =>
        [$b['score'], mb_strlen($b['name'])] <=> [$a['score'], mb_strlen($a['name'])]);
    $scored = array_slice($scored, 0, 4);

    if ($scored !== []) {
        return ['candidates' => $scored, 'choice' => $scored[0]['id'], 'learned' => false];
    }

    $skip = in_array($name, IMPORT_SKIP_WORDS, true) || $name === '';
    return ['candidates' => [], 'choice' => $skip ? 'skip' : 'new', 'learned' => false];
}

/** Alles in één: link in, voorstel voor het koppelvenster uit. Null = geen recept gevonden. */
function importRecipe(PDO $pdo, string $url): ?array
{
    $html = importFetch($url);
    $data = $html === null ? null : importFindRecipe($html);

    return $data === null ? null : importFromData($pdo, $data, $url);
}

/**
 * Zelf geplakt, voor sites die het ophalen weigeren (Allerhande). Bij AH
 * komen hoeveelheid en naam bij kopiëren op losse regels ("300 g" en dan
 * "kastanjechampignons"): een regel met alleen een hoeveelheid hoort bij
 * de volgende.
 */
function importPasted(PDO $pdo, string $name, string $ingredientText, string $steps, int $servings, string $url): array
{
    $units = implode('|', array_map(
        static fn(string $u): string => preg_quote($u, '/'),
        array_merge(array_keys(IMPORT_UNITS), IMPORT_VAGUE_UNITS)
    ));

    $lines   = [];
    $pending = '';
    foreach (preg_split('/
||
/', $ingredientText) ?: [] as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^[\d½¼¾⅓⅔.,\/\s–-]+(?:(?:' . $units . ')\.?)?$/iu', $line)) {
            $pending = trim($pending . ' ' . $line);
            continue;
        }
        $lines[] = trim($pending . ' ' . $line);
        $pending = '';
    }

    return importFromData($pdo, [
        'name'               => $name,
        'recipeYield'        => (string)$servings,
        'recipeIngredient'   => $lines,
        'recipeInstructions' => $steps,
    ], $url);
}

/** Van een Recipe-object (JSON-LD of zelf geplakt) naar het voorstel voor het koppelvenster. */
function importFromData(PDO $pdo, array $data, string $url): array
{
    $ingredients = $pdo->query('SELECT id, name FROM {ingredient} ORDER BY name')->fetchAll();
    $aliases = [];
    foreach ($pdo->query('SELECT alias, ingredient_id FROM {ingredient_alias}') as $row) {
        $aliases[$row['alias']] = $row['ingredient_id'] === null ? null : (int)$row['ingredient_id'];
    }

    $lines = [];
    foreach ((array)($data['recipeIngredient'] ?? $data['ingredients'] ?? []) as $raw) {
        $parsed = importParseLine(is_string($raw) ? $raw : importText($raw));
        if ($parsed['raw'] === '') {
            continue;
        }
        $match = importMatch($parsed, $ingredients, $aliases);
        $lines[] = $parsed + $match + ['new_name' => importCleanName($parsed['name'])];
    }

    $yield    = is_array($data['recipeYield'] ?? null) ? reset($data['recipeYield']) : ($data['recipeYield'] ?? '');
    $servings = preg_match('/\d+/', (string)$yield, $m) ? max(1, min(20, (int)$m[0])) : 4;

    $minutes = importMinutes($data['totalTime'] ?? null)
        ?: importMinutes($data['prepTime'] ?? null) + importMinutes($data['cookTime'] ?? null);
    $effort = match (true) {
        $minutes === 0  => 2,
        $minutes <= 25  => 1,
        $minutes > 60   => 3,
        default         => 2,
    };

    $name = mb_substr(importText($data['name'] ?? ''), 0, 160);

    return [
        'name'        => $name,
        'servings'    => $servings,
        'effort'      => $effort,
        'category'    => importGuessCategory($name),
        'steps'       => implode("\n", importSteps($data['recipeInstructions'] ?? [])),
        'url'         => mb_substr($url, 0, 400),
        'lines'       => $lines,
        'ingredients' => array_map(static fn(array $i): array => ['id' => (int)$i['id'], 'name' => $i['name']], $ingredients),
    ];
}

/** Onthoudt de keuzes uit het koppelvenster. $choices: [naam => id|null (overslaan)]. */
function importLearn(PDO $pdo, array $choices): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO {ingredient_alias} (alias, ingredient_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE ingredient_id = VALUES(ingredient_id)'
    );
    foreach ($choices as $alias => $id) {
        $alias = mb_substr(trim(mb_strtolower((string)$alias)), 0, 80);
        if ($alias !== '') {
            $stmt->execute([$alias, $id === null ? null : (int)$id]);
        }
    }
}
