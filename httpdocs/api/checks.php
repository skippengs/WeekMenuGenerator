<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';

/*
 * Wat er nu is afgevinkt, zodat een tweede telefoon in de winkel het ook
 * ziet zonder de pagina te herladen. Kijken mag iedereen, dus geen login.
 */

$weekId = isset($_GET['week_id']) ? (int)$_GET['week_id'] : 0;
if ($weekId <= 0) {
    jsonOut(['error' => 'Onbekende week'], 422);
}

try {
    $stmt = db()->prepare('SELECT item FROM {shopping_check} WHERE week_id = ?');
    $stmt->execute([$weekId]);
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    jsonOut(['error' => 'Ophalen mislukt'], 500);
}

header('Cache-Control: no-store');
jsonOut(['ok' => true, 'checked' => $items]);
