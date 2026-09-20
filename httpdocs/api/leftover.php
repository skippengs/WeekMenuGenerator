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
requireAdminJson();

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$weekId    = isset($input['week_id'])    ? (int)$input['week_id']    : 0;
$dayIndex  = isset($input['day_index'])  ? (int)$input['day_index']  : -1;
$sourceDay = isset($input['source_day']) ? (int)$input['source_day'] : -1;

if ($weekId <= 0 || $dayIndex < 0 || $dayIndex > 6 || $sourceDay < 0 || $sourceDay > 6) {
    jsonOut(['error' => 'Ongeldige dag'], 422);
}
if ($dayIndex === JUNK_DAY_INDEX) {
    jsonOut(['error' => 'Vrijdag blijft junkfood.'], 422);
}

try {
    $pdo = db();

    if (weekIsLocked($pdo, $weekId)) {
        jsonOut(['error' => 'Deze week is naar Bring gestuurd en staat op slot. Ontgrendel hem eerst.'], 409);
    }

    $recipe = assignLeftover($pdo, $weekId, $sourceDay, $dayIndex);
} catch (Throwable $e) {
    jsonOut(['error' => 'Restjes inplannen mislukt: ' . $e->getMessage()], 500);
}

if ($recipe === null) {
    jsonOut(['error' => 'Dit kan niet (meer) als restjesdag. Ververs de pagina.'], 422);
}

jsonOut([
    'ok'         => true,
    'id'         => $recipe['id'],
    'name'       => $recipe['name'],
    'category'   => CATEGORIES[$recipe['category']] ?? $recipe['category'],
    'effort'     => EFFORTS[$recipe['effort']] ?? '',
    'notes'      => $recipe['notes'] ?? '',
    'url'        => $recipe['url'] ?? '',
    'source_day' => $sourceDay,
]);
