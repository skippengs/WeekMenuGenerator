<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Herinnering als er voor de komende week nog geen menu is.
 *
 * Draait als geplande taak in Plesk (Geplande taken > Een PHP-script
 * uitvoeren), bijvoorbeeld zondag om 18:00. Kijkt naar de week waar morgen
 * in valt, dus op zondag is dat de week die maandag begint.
 *
 * Alleen vanaf de opdrachtregel, niet via de browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/push.php';

$pdo  = db();
$week = weekStart(date('Y-m-d', strtotime('tomorrow')));

$stmt = $pdo->prepare('SELECT COUNT(*) FROM {menu_week} WHERE week_start = ?');
$stmt->execute([$week]);

if ((int)$stmt->fetchColumn() > 0) {
    echo "Menu voor $week staat al klaar.\n";
    exit;
}

notifyUsers($pdo, 'editor', [
    'title' => 'Nog geen weekmenu',
    'body'  => 'Voor ' . weekLabel($week) . ' staat nog niets klaar.',
    'url'   => 'index.php?week=' . $week,
]);
echo "Herinnering verstuurd voor $week.\n";
