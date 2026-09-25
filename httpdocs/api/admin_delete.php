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

requireRoleJson('editor');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    jsonOut(['error' => 'Onbekend recept'], 422);
}

try {
    $pdo = db();
    $pdo->prepare('DELETE FROM {recipe} WHERE id = ?')->execute([$id]);

    $recipeTable = renderRecipeTableBody($pdo);
    $recipeCount = count(fetchRecipesForAdmin($pdo));
} catch (Throwable $e) {
    jsonOut(['error' => 'Verwijderen mislukt'], 500);
}

jsonOut([
    'ok'           => true,
    'notice'       => 'Recept verwijderd.',
    'recipe_table' => $recipeTable,
    'recipe_count' => $recipeCount,
]);
