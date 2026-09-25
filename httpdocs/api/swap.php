<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/generator.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

// Kijken mag iedereen, wijzigen niet.
requireRoleJson('editor');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$weekId = isset($input['week_id']) ? (int)$input['week_id'] : 0;
$dayA   = isset($input['day_a'])   ? (int)$input['day_a']   : -1;
$dayB   = isset($input['day_b'])   ? (int)$input['day_b']   : -1;

if ($weekId <= 0) {
    jsonOut(['error' => 'Ongeldige week'], 422);
}

try {
    $pdo = db();

    if (weekIsLocked($pdo, $weekId)) {
        jsonOut(['error' => 'Deze week is naar Bring gestuurd en staat op slot. Ontgrendel hem eerst.'], 409);
    }

    // swapProblem() controleert de dagen zelf ook (bereik, vrijdag, restjes).
    $problem = swapDays($pdo, $weekId, $dayA, $dayB);
} catch (Throwable $e) {
    jsonOut(['error' => 'Wisselen mislukt: ' . $e->getMessage()], 500);
}

if ($problem !== null) {
    jsonOut(['error' => $problem], 422);
}

jsonOut(['ok' => true]);
