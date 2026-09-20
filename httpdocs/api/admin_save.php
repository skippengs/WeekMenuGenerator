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

$id             = (int)($input['id'] ?? 0);
$name           = trim((string)($input['name'] ?? ''));
$category       = (string)($input['category'] ?? 'overig');
$effort         = (int)($input['effort'] ?? 2);
$weekendOnly    = !empty($input['weekend_only']) ? 1 : 0;
$makesLeftovers = !empty($input['makes_leftovers']) ? 1 : 0;
$servings       = max(1, min(20, (int)($input['servings'] ?? 4)));
$isMine         = !empty($input['is_mine']) ? 1 : 0;
$notes          = trim((string)($input['notes'] ?? ''));
$steps          = trim((string)($input['steps'] ?? ''));
$url            = trim((string)($input['url'] ?? ''));
$ingredients    = (string)($input['ingredients'] ?? '');

if ($name === '') {
    jsonOut(['error' => 'Geef het gerecht een naam.'], 422);
}
if (!isset(CATEGORIES[$category])) {
    $category = 'overig';
}
if ($effort < 1 || $effort > 3) {
    $effort = 2;
}

try {
    $pdo = db();

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE {recipe}
                SET name = ?, category = ?, effort = ?, weekend_only = ?, makes_leftovers = ?,
                    servings = ?, notes = ?, steps = ?, url = ?, is_mine = ?
              WHERE id = ?'
        )->execute([$name, $category, $effort, $weekendOnly, $makesLeftovers, $servings,
                    $notes ?: null, $steps ?: null, $url ?: null, $isMine, $id]);
        $notice = 'Recept bijgewerkt.';
    } else {
        $pdo->prepare(
            'INSERT INTO {recipe} (name, category, effort, weekend_only, makes_leftovers, servings, notes, steps, url, is_mine)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $category, $effort, $weekendOnly, $makesLeftovers, $servings,
                    $notes ?: null, $steps ?: null, $url ?: null, $isMine]);
        $id = (int)$pdo->lastInsertId();
        $notice = 'Recept toegevoegd.';
    }

    syncIngredients($pdo, $id, $ingredients);

    $recipeTable = renderRecipeTableBody($pdo);
    $recipeCount = count(fetchRecipesForAdmin($pdo));
    $pantryList  = renderPantryItems(fetchIngredientsForAdmin($pdo));
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut([
    'ok'           => true,
    'notice'       => $notice,
    'id'           => $id,
    'recipe_table' => $recipeTable,
    'recipe_count' => $recipeCount,
    'pantry_list'  => $pantryList,
]);
