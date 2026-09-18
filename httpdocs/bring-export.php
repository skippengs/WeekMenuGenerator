<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Stuurt de boodschappen naar Bring! en zet de week daarna op slot.
 *
 * De knop wijst hierheen in plaats van rechtstreeks naar Bring, want een
 * gewone link naar hun server zouden wij nooit zien. Nu weten we dat de
 * lijst verstuurd is en kunnen we voorkomen dat het menu daarna nog
 * verandert; anders klopt wat er in Bring staat niet meer.
 */

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/generator.php';

startSession();
requireAdmin();

$pdo     = db();
$current = weekStart($_GET['week'] ?? null);
$week    = loadWeek($pdo, $current);

if ($week === null) {
    http_response_code(404);
    exit('Voor die week staat nog geen menu klaar.');
}

// Op slot, maar alleen de eerste keer: het tijdstip blijft staan.
if ($week['locked_at'] === null) {
    $pdo->prepare('UPDATE {menu_week} SET locked_at = NOW() WHERE id = ?')
        ->execute([$week['id']]);
}

$scheme = empty($_SERVER['HTTPS']) ? 'http' : 'https';
$dir    = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$base   = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;

$listUrl = $base . '/bring.php?week=' . urlencode($current);

$deeplink = 'https://api.getbring.com/rest/bringrecipes/deeplink'
          . '?url=' . urlencode($listUrl)
          . '&source=web&baseQuantity=1&requestedQuantity=1';

header('Location: ' . $deeplink, true, 302);
exit;
