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

requireAdminJson();

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$weekId = isset($input['week_id']) ? (int)$input['week_id'] : 0;
$locked = !empty($input['locked']);

if ($weekId <= 0) {
    jsonOut(['error' => 'Onbekende week'], 422);
}

try {
    $pdo = db();
    $pdo->prepare('UPDATE {menu_week} SET locked_at = ? WHERE id = ?')
        ->execute([$locked ? date('Y-m-d H:i:s') : null, $weekId]);
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut(['ok' => true, 'locked' => $locked]);
