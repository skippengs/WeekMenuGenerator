<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Boodschappenlijst als recept-pagina, zodat de Bring! app hem kan inlezen.
 *
 * Bring haalt deze pagina zelf op vanaf hun servers. Daarom staat er geen
 * login op: zonder publieke toegang kan Bring er niet bij. Er staat ook
 * niets gevoeligs op, alleen wat er deze week gekocht moet worden.
 *
 * De gegevens staan als JSON-LD in de pagina. Microdata met
 * itemprop="ingredients" stond in hun oudere voorbeelden, maar hun
 * integratiecheck wees dat af; JSON-LD wordt wel herkend.
 */

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/deals.php';
require __DIR__ . '/inc/generator.php';

$pdo     = db();
$current = weekStart($_GET['week'] ?? null);
$week    = loadWeek($pdo, $current);

if ($week === null) {
    http_response_code(404);
    exit('Voor die week staat nog geen menu klaar.');
}

$shopping = shoppingList($pdo, $week['id']);

/**
 * Hoeveelheid netjes opschrijven. Dezelfde afspraken als in de app:
 * grammen op tientallen, boven de duizend naar kilo's. Punt als
 * decimaalteken, want daar kan een parser beter mee overweg.
 */
function bringAmount(?float $value, ?string $unit): string
{
    if ($value === null || $value <= 0) {
        return '';
    }

    if (($unit === 'g' || $unit === 'ml') && $value >= 1000) {
        $value = $value / 1000;
        $unit  = $unit === 'g' ? 'kg' : 'l';
    }

    if ($unit === 'g' || $unit === 'ml') {
        $rounded = $value >= 100 ? round($value / 10) * 10
                 : ($value >= 20 ? round($value / 5) * 5 : round($value));
    } elseif ($unit === 'kg' || $unit === 'l') {
        $rounded = round($value, 1);
    } else {
        $rounded = round($value * 2) / 2;
    }

    $text = rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');

    return $unit ? $text . ' ' . $unit : $text;
}

// Alleen wat nog gehaald moet worden; afgevinkte dingen liggen al in de kar.
// Staat het in de aanbieding, dan komt de winkel erachter in de naam. Bring
// vertaalt alleen het herkende deel van de naam, dus dit stukje blijft
// Nederlands ook als de app op een andere taal staat - maar zo zie je in
// de app zelf waar het voordeligst is en kun je zelf kiezen of je gaat.
$lines = [];
foreach ($shopping as $items) {
    foreach ($items as $item) {
        if (!empty($item['checked'])) {
            continue;
        }
        $amount = bringAmount($item['amount'] ?? null, $item['unit'] ?? null);
        $name   = $item['name'];
        $best   = $item['deals'][0] ?? null;
        if ($best !== null) {
            $name .= ' (aanbieding bij ' . $best['label'] . ')';
        }
        $lines[] = trim($amount . ' ' . $name);
    }
}

$title = 'Boodschappen ' . weekLabel($current);

// Absolute adressen: Bring leest deze pagina van buitenaf.
$scheme = empty($_SERVER['HTTPS']) ? 'http' : 'https';
$dir    = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$base   = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;

$recipe = [
    '@context'           => 'https://schema.org',
    '@type'              => 'Recipe',
    'name'               => $title,
    'description'        => 'De boodschappen voor het weekmenu van ' . weekLabel($current) . '.',
    'image'              => $base . '/assets/icon-512.png',
    'author'             => ['@type' => 'Organization', 'name' => 'Weekmenu'],
    'recipeCategory'     => 'Boodschappen',
    'recipeCuisine'      => 'Nederlands',
    'recipeYield'        => '1 week',
    'prepTime'           => 'PT0M',
    'cookTime'           => 'PT0M',
    'totalTime'          => 'PT0M',
    'datePublished'      => $current,
    'recipeIngredient'   => $lines,
    'recipeInstructions' => [
        ['@type' => 'HowToStep', 'text' => 'Zet de boodschappen in je lijst en ga naar de winkel.'],
    ],
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?></title>
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<meta name="robots" content="noindex">
<script type="application/ld+json">
<?= json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>

</script>
</head>
<body class="install">
<main class="wrap">

    <h1><?= esc($title) ?></h1>
    <p class="hint">Deze pagina is bedoeld voor de Bring! app.</p>

    <?php if ($lines === []): ?>
        <p>Niets meer te halen; alles staat afgevinkt.</p>
    <?php else: ?>
        <ul class="detail-ingredients" style="flex-direction:column;align-items:flex-start">
            <?php foreach ($lines as $line): ?>
                <li><?= esc($line) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a class="btn btn-ghost" href="index.php?week=<?= esc($current) ?>">Terug naar het weekmenu</a></p>
</main>
</body>
</html>
