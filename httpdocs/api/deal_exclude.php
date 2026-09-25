<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/deals.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireRoleJson('editor');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$ingredientId = isset($input['ingredient_id']) ? (int)$input['ingredient_id'] : 0;
$retailer     = (string)($input['retailer'] ?? '');
$productKey   = trim((string)($input['product_key'] ?? ''));

if ($ingredientId <= 0 || !array_key_exists($retailer, DEALS_RETAILERS) || $productKey === '' || mb_strlen($productKey) > 160) {
    jsonOut(['error' => 'Onbekend product'], 422);
}

try {
    excludeDeal(db(), $ingredientId, $retailer, $productKey);
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut(['ok' => true]);
