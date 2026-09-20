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

$fromId = (int)($input['from_id'] ?? 0);
$intoId = (int)($input['into_id'] ?? 0);

if ($fromId <= 0 || $intoId <= 0 || $fromId === $intoId) {
    jsonOut(['error' => 'Kies twee verschillende ingrediënten.'], 422);
}

try {
    $pdo = db();

    $find = $pdo->prepare('SELECT name, is_pantry_item FROM {ingredient} WHERE id = ?');
    $find->execute([$fromId]);
    $from = $find->fetch();
    $find->execute([$intoId]);
    $into = $find->fetch();

    if (!$from || !$into) {
        jsonOut(['error' => 'Ingrediënt niet gevonden.'], 404);
    }

    // Recepten die het "van"-ingrediënt gebruiken, over op het "naar"-
    // ingrediënt. Staat een recept toevallig al op allebei (dubbel
    // ingevoerd), dan blijft die ene rij op het "van"-ingrediënt staan
    // (UPDATE IGNORE slaat 'm over vanwege de primary key) en verdwijnt hij
    // vanzelf met de delete hieronder: de foreign key staat op CASCADE.
    $pdo->prepare('UPDATE IGNORE {recipe_ingredient} SET ingredient_id = ? WHERE ingredient_id = ?')
        ->execute([$intoId, $fromId]);

    if ((int)$from['is_pantry_item'] === 1) {
        $pdo->prepare('UPDATE {ingredient} SET is_pantry_item = 1 WHERE id = ?')->execute([$intoId]);
    }

    $pdo->prepare('DELETE FROM {ingredient} WHERE id = ?')->execute([$fromId]);

    $ingredients       = fetchIngredientsForAdmin($pdo);
    $pantryList        = renderPantryItems($ingredients);
    $ingredientOptions = renderIngredientOptions($ingredients);
    $ingredientNames   = array_column($ingredients, 'name');
} catch (Throwable $e) {
    jsonOut(['error' => 'Samenvoegen mislukt'], 500);
}

jsonOut([
    'ok'                 => true,
    'notice'             => $from['name'] . ' samengevoegd met ' . $into['name'] . '.',
    'pantry_list'        => $pantryList,
    'ingredient_options' => $ingredientOptions,
    'ingredient_names'   => $ingredientNames,
]);
