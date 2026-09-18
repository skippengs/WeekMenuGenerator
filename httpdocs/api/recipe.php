<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';

startSession();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    jsonOut(['error' => 'Onbekend recept'], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id, name, category, effort, servings, notes, steps, url
           FROM {recipe}
          WHERE id = ?'
    );
    $stmt->execute([$id]);
    $recipe = $stmt->fetch();

    if (!$recipe) {
        jsonOut(['error' => 'Recept niet gevonden'], 404);
    }

    $stmt = $pdo->prepare(
        'SELECT i.name, ri.amount, ri.unit
           FROM {recipe_ingredient} ri
           JOIN {ingredient} i ON i.id = ri.ingredient_id
          WHERE ri.recipe_id = ?
          ORDER BY i.category, i.name'
    );
    $stmt->execute([$id]);

    $ingredients = [];
    foreach ($stmt as $row) {
        $ingredients[] = [
            'name'   => $row['name'],
            'amount' => $row['amount'] === null ? null : (float)$row['amount'],
            'unit'   => $row['unit'],
        ];
    }
} catch (Throwable $e) {
    jsonOut(['error' => 'Ophalen mislukt'], 500);
}

// Bereiding staat als losse regels opgeslagen.
$steps = [];
foreach (preg_split('/\r\n|\r|\n/', (string)$recipe['steps']) ?: [] as $line) {
    $line = trim($line);
    if ($line !== '') {
        $steps[] = $line;
    }
}

jsonOut([
    'ok'          => true,
    'id'          => (int)$recipe['id'],
    'name'        => $recipe['name'],
    'category'    => CATEGORIES[$recipe['category']] ?? $recipe['category'],
    'effort'      => EFFORTS[(int)$recipe['effort']] ?? '',
    'servings'    => (int)$recipe['servings'],
    'notes'       => (string)$recipe['notes'],
    'url'         => (string)$recipe['url'],
    'ingredients' => $ingredients,
    'steps'       => $steps,
]);
