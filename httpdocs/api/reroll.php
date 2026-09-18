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

if ($weekId <= 0 || $dayIndex < 0 || $dayIndex > 6) {
    jsonOut(['error' => 'Ongeldige dag'], 422);
}
if ($dayIndex === JUNK_DAY_INDEX) {
    jsonOut(['error' => 'Vrijdag blijft junkfood.'], 422);
}

try {
    $pick = rerollDay(db(), $weekId, $dayIndex);
} catch (Throwable $e) {
    jsonOut(['error' => 'Opnieuw kiezen mislukt: ' . $e->getMessage()], 500);
}

if ($pick === null) {
    jsonOut(['error' => 'Geen ander recept beschikbaar. Voeg er een paar toe.'], 404);
}

jsonOut([
    'ok'       => true,
    'id'       => $pick['id'],
    'name'     => $pick['name'],
    'category' => CATEGORIES[$pick['category']] ?? $pick['category'],
    'effort'   => EFFORTS[$pick['effort']] ?? '',
    'notes'    => $pick['notes'] ?? '',
    'url'      => $pick['url'] ?? '',
]);
