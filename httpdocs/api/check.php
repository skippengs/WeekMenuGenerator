<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

// Kijken mag iedereen, afvinken alleen een bewerker.
requireRoleJson('editor');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$weekId  = isset($input['week_id']) ? (int)$input['week_id'] : 0;
$item    = trim((string)($input['item'] ?? ''));
$checked = !empty($input['checked']);

if ($weekId <= 0 || $item === '' || mb_strlen($item) > 80) {
    jsonOut(['error' => 'Ongeldig item'], 422);
}

try {
    $pdo = db();

    if ($checked) {
        $pdo->prepare(
            'INSERT IGNORE INTO {shopping_check} (week_id, item) VALUES (?, ?)'
        )->execute([$weekId, $item]);
    } else {
        $pdo->prepare(
            'DELETE FROM {shopping_check} WHERE week_id = ? AND item = ?'
        )->execute([$weekId, $item]);
    }
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut(['ok' => true, 'item' => $item, 'checked' => $checked]);
