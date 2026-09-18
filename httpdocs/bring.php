<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Boodschappenlijst als recept-pagina, zodat de Bring! app hem kan inlezen.
 *
 * Bring haalt deze pagina zelf op vanaf hun servers en leest de
 * schema.org-opmaak eruit. Daarom staat er geen login op: zonder
 * publieke toegang kan Bring er niet bij. Er staat ook niets gevoeligs
 * op, alleen wat er deze week gekocht moet worden.
 *
 * De opmaak volgt de voorbeelden uit de Bring! Import Developer Guide:
 * microdata met itemprop="ingredients". recipeIngredient staat er als
 * tweede naam bij, want dat is de moderne schrijfwijze.
 */

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/settings.php';
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
 * grammen op tientallen, boven de duizend naar kilo's.
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
$lines = [];
foreach ($shopping as $items) {
    foreach ($items as $item) {
        if (!empty($item['checked'])) {
            continue;
        }
        $amount  = bringAmount($item['amount'] ?? null, $item['unit'] ?? null);
        $lines[] = trim($amount . ' ' . $item['name']);
    }
}

$title = 'Boodschappen ' . weekLabel($current);
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
</head>
<body class="install">
<main class="wrap" itemscope itemtype="http://schema.org/Recipe">

    <h1 itemprop="name"><?= esc($title) ?></h1>

    <p class="hint">
        <span>Voor </span><span itemprop="yield">1</span> week &mdash;
        deze pagina is bedoeld voor de Bring! app.
    </p>

    <meta itemprop="recipeCategory" content="Boodschappen">
    <meta itemprop="author" content="Weekmenu">

    <?php if ($lines === []): ?>
        <p>Niets meer te halen; alles staat afgevinkt.</p>
    <?php else: ?>
        <ul class="detail-ingredients" style="flex-direction:column;align-items:flex-start">
            <?php foreach ($lines as $line): ?>
                <li itemprop="recipeIngredient ingredients"><?= esc($line) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p><a class="btn btn-ghost" href="index.php?week=<?= esc($current) ?>">Terug naar het weekmenu</a></p>
</main>
</body>
</html>
