<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Meldingen voor de avond ervoor.
 *
 * Draait als geplande taak in Plesk (Geplande taken > Een PHP-script
 * uitvoeren), elke dag rond 18:00. Kijkt naar morgen:
 *   - staat er een gerecht met "ligt in de vriezer", dan: haal het eruit;
 *   - is morgen maandag en is er voor die week nog geen menu: maak er een.
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

$pdo      = db();
$tomorrow = new DateTimeImmutable('tomorrow');
$week     = weekStart($tomorrow->format('Y-m-d'));
$dayIndex = (int)$tomorrow->format('N') - 1;

/* --- ontdooien --- */
$stmt = $pdo->prepare(
    'SELECT r.name
       FROM {menu_entry} me
       JOIN {menu_week} mw ON mw.id = me.week_id
       JOIN {recipe} r ON r.id = me.recipe_id
      WHERE mw.week_start = ? AND me.day_index = ? AND me.thaw = 1 AND me.is_leftover = 0'
);
$stmt->execute([$week, $dayIndex]);
$thawName = $stmt->fetchColumn();

if ($thawName !== false) {
    notifyUsers($pdo, 'reader', [
        'title' => 'Uit de vriezer halen',
        'body'  => 'Morgen: ' . $thawName . '. Leg het vanavond in de koelkast.',
        'url'   => 'index.php?week=' . $week,
    ]);
    echo "Ontdooi-melding verstuurd voor $thawName.\n";
}

/* --- nog geen weekmenu --- */
if ($dayIndex !== 0) {
    exit;
}

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
