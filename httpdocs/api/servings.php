<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/settings.php';
require __DIR__ . '/../inc/generator.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$weekId   = isset($input['week_id'])   ? (int)$input['week_id']   : 0;
$dayIndex = isset($input['day_index']) ? (int)$input['day_index'] : -1;
$servings = isset($input['servings'])  ? (int)$input['servings']  : 0;

if ($weekId <= 0 || $dayIndex < 0 || $dayIndex > 6) {
    jsonOut(['error' => 'Ongeldige dag'], 422);
}
if ($servings < 1 || $servings > 20) {
    jsonOut(['error' => 'Kies tussen 1 en 20 personen.'], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'UPDATE {menu_entry} SET servings = ? WHERE week_id = ? AND day_index = ?'
    );
    $stmt->execute([$servings, $weekId, $dayIndex]);

    if ($stmt->rowCount() === 0) {
        // Kan ook betekenen dat het al op deze waarde stond; dan is er niets mis.
        $check = $pdo->prepare('SELECT COUNT(*) FROM {menu_entry} WHERE week_id = ? AND day_index = ?');
        $check->execute([$weekId, $dayIndex]);
        if ((int)$check->fetchColumn() === 0) {
            jsonOut(['error' => 'Die dag bestaat niet meer. Ververs de pagina.'], 404);
        }
    }

    // De boodschappenlijst verandert mee, dus die sturen we meteen terug.
    $shopping = shoppingList($pdo, $weekId);
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut([
    'ok'        => true,
    'day_index' => $dayIndex,
    'servings'  => $servings,
    'shopping'  => $shopping,
]);
