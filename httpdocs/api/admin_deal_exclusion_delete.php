<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/admin_helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireAdminJson();

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$ingredientId = (int)($input['ingredient_id'] ?? 0);
$retailer     = (string)($input['retailer'] ?? '');
$productKey   = trim((string)($input['product_key'] ?? ''));

if ($ingredientId <= 0 || $retailer === '' || $productKey === '') {
    jsonOut(['error' => 'Onbekende uitsluiting'], 422);
}

try {
    $pdo = db();
    $pdo->prepare('DELETE FROM {deal_exclusion} WHERE ingredient_id = ? AND retailer = ? AND product_key = ?')
        ->execute([$ingredientId, $retailer, $productKey]);

    $exclusionTable = renderDealExclusionTable($pdo);
    $exclusionCount = count(fetchDealExclusionsForAdmin($pdo));
} catch (Throwable $e) {
    jsonOut(['error' => 'Verwijderen mislukt'], 500);
}

jsonOut([
    'ok'                   => true,
    'notice'               => 'Uitsluiting verwijderd. Bij de volgende ververing kan dit product weer meedoen.',
    'deal_exclusion_table' => $exclusionTable,
    'deal_exclusion_count' => $exclusionCount,
]);
