<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/*
 * Aanbiedingen via prijsprofeet.nl: een onofficiele, niet door ons gemaakte
 * dienst die supermarktacties van (onder andere) AH, Jumbo, Aldi en PLUS
 * bundelt. Geen sleutel nodig voor het gratis niveau.
 *
 * Alles hier is best effort. Gaat een aanroep mis, is de dienst traag of
 * ligt hij eruit, dan gaat het genereren van het menu gewoon door zonder
 * kortingsweging - dit mag nooit de reden zijn dat er een 500 komt te
 * staan.
 */

const DEALS_SETTING_CHECKED_AT = 'deals_checked_at';

// Woorden die nooit een aanwijzing zijn dat een product afwijkt: lidwoorden,
// voegwoorden en de eenheden/aanduidingen die toch al overal in productnamen
// staan.
const DEAL_NOISE_WORDS = [
    'de', 'het', 'een', 'en', 'van', 'voor', 'bij', 'per', 'ca', 'circa', 'met',
    'stuk', 'stuks', 'g', 'gram', 'kg', 'ml', 'l', 'liter', 'el', 'tl',
    'pak', 'pakje', 'pot', 'bosje', 'blik', 'blikje', 'plak', 'plakken', 'x',
    'in', 'fles', 'multipack', 'voordeelpack', 'kleinverpakking', 'voordeelverpakking',
];

// Algemene aanduidingen die een product níet tot iets anders maken: kleur,
// bio, vers, soort aardappel/kaas/melk. Een product komt alleen door de
// filter als elk woord in de naam het ingredient, het merk, de winkel, ruis
// of een woord uit deze lijst is. Een onbekend woord ("mix", "gebraden",
// "chips", "ovenschotel") betekent dus: ander product. Mist er hier een
// woord, dan mis je hooguit een aanbieding - geen verkeerde op de lijst.
const DEAL_VARIANT_WORDS = [
    'rode', 'rood', 'gele', 'geel', 'groene', 'groen', 'oranje', 'witte', 'wit',
    'bio', 'biologisch', 'biologische', 'vers', 'verse', 'scharrel', 'naturel',
    'gemengd', 'gemengde', 'grote', 'groot', 'kleine', 'klein', 'extra', 'kwaliteit',
    'mager', 'magere', 'los', 'losse', 'hele', 'heel', 'half', 'om',
    'kruimig', 'kruimige', 'vastkokend', 'vastkokende', 'vrij',
    'jong', 'jonge', 'belegen', 'oud', 'oude', 'geraspte', 'plakken',
    'halfvol', 'halfvolle', 'vol', 'volle', 'gesneden', 'fijngesneden', 'manis',
];

/**
 * Een enkele GET-aanroep naar de aanbiedingen-api, met een korte timeout.
 * Geeft bij elke vorm van falen (geen verbinding, timeout, geen 200, geen
 * geldige JSON) gewoon null terug - nooit een exception naar buiten.
 */
function dealsApiGet(string $path, array $query): ?array
{
    $url = rtrim(DEALS_API_BASE, '/') . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => DEALS_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => DEALS_HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . DEALS_USER_AGENT],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $failed = curl_errno($ch) !== 0;
    curl_close($ch);

    if ($failed || $status !== 200 || !is_string($body)) {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Identificeert een product zo stabiel mogelijk: het eigen id van
 * prijsprofeet.nl als dat er is (blijft gelijk tussen scrapes, ook al
 * verandert de datumtag in product_id), anders de productnaam zelf.
 * Wordt aan de ene kant gebruikt om een uitsluiting op te slaan, aan de
 * andere kant om te checken of een gevonden product daaraan matcht - dus
 * moet exact hetzelfde blijven op beide plekken.
 */
function dealProductKey(?string $baseProductId, string $productName): string
{
    $baseProductId = trim((string)$baseProductId);
    if ($baseProductId !== '') {
        return 'id:' . $baseProductId;
    }
    return 'name:' . mb_strtolower(trim($productName), 'UTF-8');
}

/** Woorden uit tekst, kleine letters, leestekens en losse getallen eruit. */
function dealWords(string $text): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
    $words = preg_split('/\s+/u', trim($text)) ?: [];

    return array_values(array_filter(
        $words,
        static fn(string $w): bool => $w !== '' && !preg_match('/^\d+$/', $w)
    ));
}

/** Grove stam voor enkelvoud/meervoud: "kipfilets", "aardappelen" -> "kipfilet", "aardappel". */
function dealStem(string $word): string
{
    return preg_replace('/(en|s)$/u', '', $word) ?? $word;
}

/**
 * Is dit product echt het ingredient? Alle ingredientwoorden moeten erin
 * staan, en elk ander woord moet merk, winkel, ruis of een algemene
 * aanduiding (DEAL_VARIANT_WORDS) zijn. Vergelijkt op stam, zodat
 * "2 kipfilets" en "Jumbo Wortelen" gewoon meetellen.
 */
function dealIsIngredientMatch(string $ingredientName, ?string $brand, string $productName): bool
{
    $ingredient = array_map('dealStem', dealWords($ingredientName));
    $product    = array_map('dealStem', dealWords($productName));
    if ($ingredient === [] || array_diff($ingredient, $product) !== []) {
        return false;
    }
    $known = array_map('dealStem', array_merge(
        $ingredient, dealWords((string)$brand), DEAL_NOISE_WORDS, DEAL_VARIANT_WORDS, dealStoreWords()
    ));
    return array_diff($product, $known) === [];
}

/**
 * Namen van de winkels zelf ("jumbo", "ah", ...): prijsprofeet.nl levert
 * het merk niet bij elk product, dus de winkelnaam in "Jumbo Prei" moet
 * ook zonder merkveld als bekend woord tellen.
 */
function dealStoreWords(): array
{
    return array_map(static fn(string $label): string => mb_strtolower($label, 'UTF-8'), array_values(DEALS_RETAILERS));
}

/**
 * Welke producten je zelf hebt afgekeurd voor dit ingredient (bijvoorbeeld
 * "gebraden gehakt" onder "gehakt"), als [$retailer => [$productKey => true]].
 */
function fetchDealExclusions(PDO $pdo, int $ingredientId): array
{
    $stmt = $pdo->prepare('SELECT retailer, product_key FROM {deal_exclusion} WHERE ingredient_id = ?');
    $stmt->execute([$ingredientId]);

    $out = [];
    foreach ($stmt as $row) {
        $out[$row['retailer']][$row['product_key']] = true;
    }
    return $out;
}

/**
 * Sluit een product uit voor dit ingredient/deze winkel ("klopt niet" in
 * het kortingsvenster) en haalt de nu gecachte deal van die winkel meteen
 * weg, zodat de badge niet pas bij de volgende refreshDeals() klopt.
 */
function excludeDeal(PDO $pdo, int $ingredientId, string $retailer, string $productKey): void
{
    $pdo->prepare(
        'INSERT IGNORE INTO {deal_exclusion} (ingredient_id, retailer, product_key) VALUES (?, ?, ?)'
    )->execute([$ingredientId, $retailer, $productKey]);

    $pdo->prepare('DELETE FROM {deal} WHERE ingredient_id = ? AND retailer = ?')
        ->execute([$ingredientId, $retailer]);
}

/**
 * Zoekt aanbiedingen voor een ingredient en houdt per winkel de goedkoopste
 * over. Geeft null terug als de aanroep zelf mislukte (dienst niet
 * bereikbaar) en een lege array als hij wel lukte maar er niets bruikbaars
 * bij zat - dat onderscheid bepaalt straks of de cache blijft staan of
 * leeggemaakt wordt.
 *
 * De zoek-api matcht fuzzy en geeft veel bijvangst ("Mix voor gehakt",
 * "Lays Chips paprika", "AH Gebraden gehakt"). dealIsIngredientMatch()
 * laat alleen producten door waarvan de naam niets anders zegt dan het
 * ingredient plus merk en algemene aanduidingen. Wat daarna nog fout is,
 * vangt $exclusions - zelf aangevinkt via de kortingsknop "klopt niet" -
 * dat hier per winkel wordt weggefilterd vóórdat de goedkoopste gekozen
 * wordt.
 */
function fetchDealsForIngredient(string $ingredientName, array $exclusions = []): ?array
{
    $data = dealsApiGet('/search', [
        'q'                => $ingredientName,
        'promotion_status' => 'active',
        // Ruim: de echte producten staan vaak ná de bijvangst ("gehakt": 20
        // sausmixen, dan pas het vlees).
        'page_size'        => 100,
    ]);
    if ($data === null || !isset($data['results']) || !is_array($data['results'])) {
        return null;
    }

    $best = [];
    foreach ($data['results'] as $row) {
        $retailer       = (string)($row['retailer'] ?? '');
        $name           = (string)($row['name'] ?? '');
        $price          = $row['price'] ?? null;
        $brand          = isset($row['brand']) ? (string)$row['brand'] : null;
        $baseProductId  = isset($row['base_product_id']) ? (string)$row['base_product_id'] : null;

        if (!array_key_exists($retailer, DEALS_RETAILERS) || $price === null) {
            continue;
        }
        if (!dealIsIngredientMatch($ingredientName, $brand, $name)) {
            continue;
        }

        $key = dealProductKey($baseProductId, $name);
        if (isset($exclusions[$retailer][$key])) {
            continue;
        }

        if (!isset($best[$retailer]) || (float)$price < (float)$best[$retailer]['price']) {
            $best[$retailer] = [
                'retailer'           => $retailer,
                'product_name'       => $name,
                'base_product_id'    => $baseProductId,
                'brand'              => $brand,
                'price'              => (float)$price,
                'original_price'     => isset($row['original_price']) ? (float)$row['original_price'] : null,
                'savings_percentage' => isset($row['savings_percentage']) ? (float)$row['savings_percentage'] : null,
                'valid_until'        => $row['valid_until'] ?? null,
            ];
        }
    }

    return array_values($best);
}

/**
 * Ververst de kortingscache voor elk kenmerkend ingredient van een actief
 * recept. Hooguit eens per DEALS_REFRESH_HOURS, ook als er niets te
 * controleren valt - anders bonkt elke aanroep van generateWeek() opnieuw
 * tegen een dienst die toch niet reageert.
 *
 * Wordt alleen aangeroepen bij het genereren van een weekmenu
 * (api/generate.php), niet bij een reroll of een paginaweergave: dat houdt
 * het aantal aanroepen ruim onder de rate limit van de dienst.
 */
function refreshDeals(PDO $pdo, bool $force = false): void
{
    if (!$force) {
        $checkedAt = getSetting($pdo, DEALS_SETTING_CHECKED_AT, '');
        if ($checkedAt !== '' && (time() - strtotime($checkedAt)) < DEALS_REFRESH_HOURS * 3600) {
            return;
        }
    }

    setSetting($pdo, DEALS_SETTING_CHECKED_AT, date('Y-m-d H:i:s'));

    $names = $pdo->query(
        'SELECT DISTINCT i.id, i.name
           FROM {ingredient} i
           JOIN {recipe_ingredient} ri ON ri.ingredient_id = i.id
           JOIN {recipe} r ON r.id = ri.recipe_id
          WHERE ri.is_key = 1 AND r.is_active = 1'
    )->fetchAll();

    if ($names === []) {
        return;
    }

    $del = $pdo->prepare('DELETE FROM {deal} WHERE ingredient_id = ?');
    $ins = $pdo->prepare(
        'INSERT INTO {deal} (ingredient_id, retailer, product_name, base_product_id, brand, price, original_price, savings_percentage, valid_until, checked_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    foreach ($names as $row) {
        $exclusions = fetchDealExclusions($pdo, (int)$row['id']);
        $deals      = fetchDealsForIngredient($row['name'], $exclusions);
        if ($deals === null) {
            // Aanroep mislukt voor dit ingredient: bestaande cache laten staan.
            continue;
        }

        $del->execute([$row['id']]);
        foreach ($deals as $d) {
            $ins->execute([
                $row['id'], $d['retailer'], $d['product_name'], $d['base_product_id'], $d['brand'], $d['price'],
                $d['original_price'], $d['savings_percentage'], $d['valid_until'],
            ]);
        }
    }
}

/** [$recipeId => aantal kenmerkende ingredienten met nu een aanbieding]. */
function dealHitCounts(PDO $pdo): array
{
    $hits = [];
    $rows = $pdo->query(
        'SELECT ri.recipe_id, COUNT(DISTINCT ri.ingredient_id) AS hits
           FROM {recipe_ingredient} ri
           JOIN {deal} d ON d.ingredient_id = ri.ingredient_id
          WHERE ri.is_key = 1
          GROUP BY ri.recipe_id'
    );
    foreach ($rows as $r) {
        $hits[(int)$r['recipe_id']] = (int)$r['hits'];
    }
    return $hits;
}

/** Zet de rijen van een deal/week_deal select om naar [$ingredientId => [...]], beste aanbieding eerst. */
function groupDealsByIngredient(PDOStatement $stmt): array
{
    $out = [];
    foreach ($stmt as $row) {
        $out[(int)$row['ingredient_id']][] = [
            'retailer'           => $row['retailer'],
            'label'               => DEALS_RETAILERS[$row['retailer']] ?? $row['retailer'],
            'product_name'        => $row['product_name'],
            // Alleen bij levende deals (week_deal heeft geen base_product_id) -
            // de uitsluitknop verschijnt toch alleen als de week nog niet op slot staat.
            'product_key'         => dealProductKey($row['base_product_id'] ?? null, $row['product_name']),
            'price'                => (float)$row['price'],
            'original_price'      => $row['original_price'] !== null ? (float)$row['original_price'] : null,
            'savings_percentage'  => $row['savings_percentage'] !== null ? (float)$row['savings_percentage'] : null,
            'valid_until'          => $row['valid_until'],
        ];
    }
    return $out;
}

/** Actuele kortingen per ingredient-id, voor een week die nog niet op slot staat. */
function liveDealsByIngredient(PDO $pdo, array $ingredientIds): array
{
    if ($ingredientIds === []) {
        return [];
    }

    $in = implode(',', array_fill(0, count($ingredientIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT ingredient_id, retailer, product_name, base_product_id, price, original_price, savings_percentage, valid_until
           FROM {deal}
          WHERE ingredient_id IN ($in)
          ORDER BY savings_percentage DESC"
    );
    $stmt->execute($ingredientIds);

    return groupDealsByIngredient($stmt);
}

/** Bevroren kortingen zoals ze waren op het moment dat de week op slot ging. */
function weekDealsByIngredient(PDO $pdo, int $weekId): array
{
    $stmt = $pdo->prepare(
        'SELECT ingredient_id, retailer, product_name, price, original_price, savings_percentage, valid_until
           FROM {week_deal}
          WHERE week_id = ?
          ORDER BY savings_percentage DESC'
    );
    $stmt->execute([$weekId]);

    return groupDealsByIngredient($stmt);
}

/**
 * Legt de huidige kortingen van deze week vast in {week_deal}. Aangeroepen
 * op het moment dat een week op slot gaat, zodat de boodschappenlijst
 * blijft kloppen ook als prijsprofeet.nl later niet meer bereikbaar is of
 * de actie inmiddels voorbij blijkt.
 */
function snapshotWeekDeals(PDO $pdo, int $weekId): void
{
    $pdo->prepare('DELETE FROM {week_deal} WHERE week_id = ?')->execute([$weekId]);

    $pdo->prepare(
        'INSERT IGNORE INTO {week_deal}
                (week_id, ingredient_id, retailer, product_name, price, original_price, savings_percentage, valid_until)
         SELECT DISTINCT ?, d.ingredient_id, d.retailer, d.product_name, d.price, d.original_price, d.savings_percentage, d.valid_until
           FROM {deal} d
           JOIN {recipe_ingredient} ri ON ri.ingredient_id = d.ingredient_id
           JOIN {menu_entry} me ON me.recipe_id = ri.recipe_id
          WHERE me.week_id = ? AND me.is_leftover = 0'
    )->execute([$weekId, $weekId]);
}
