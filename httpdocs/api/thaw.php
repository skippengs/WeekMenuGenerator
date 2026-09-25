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

requireRoleJson('editor');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$weekId   = isset($input['week_id'])   ? (int)$input['week_id']   : 0;
$dayIndex = isset($input['day_index']) ? (int)$input['day_index'] : -1;
$thaw     = !empty($input['thaw']) ? 1 : 0;

if ($weekId <= 0 || $dayIndex < 0 || $dayIndex > 6) {
    jsonOut(['error' => 'Ongeldige dag'], 422);
}

// Geen slotcontrole: "ligt het in de vriezer" verandert het menu niet,
// net als afvinken. Wel alleen op een dag met een eigen gerecht.
try {
    $stmt = db()->prepare(
        'UPDATE {menu_entry} SET thaw = ?
          WHERE week_id = ? AND day_index = ? AND recipe_id IS NOT NULL AND is_leftover = 0'
    );
    $stmt->execute([$thaw, $weekId, $dayIndex]);
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut(['ok' => true, 'day_index' => $dayIndex, 'thaw' => $thaw === 1]);
