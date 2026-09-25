<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/admin_helpers.php';

startSession();
requireRoleJson('editor');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    jsonOut(['error' => 'Onbekend recept'], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM {recipe} WHERE id = ?');
    $stmt->execute([$id]);
    $recipe = $stmt->fetch();

    if (!$recipe) {
        jsonOut(['error' => 'Recept niet gevonden'], 404);
    }

    $ingredients = ingredientsAsText($pdo, $id);
} catch (Throwable $e) {
    jsonOut(['error' => 'Ophalen mislukt'], 500);
}

jsonOut([
    'ok'              => true,
    'id'              => (int)$recipe['id'],
    'name'            => (string)$recipe['name'],
    'category'        => (string)$recipe['category'],
    'effort'          => (int)$recipe['effort'],
    'servings'        => (int)$recipe['servings'],
    'weekend_only'    => (int)$recipe['weekend_only'],
    'makes_leftovers' => (int)$recipe['makes_leftovers'],
    'is_mine'         => (int)$recipe['is_mine'],
    'preference'      => (int)$recipe['preference'],
    'season'          => seasonMonths($recipe['season']),
    'notes'           => (string)$recipe['notes'],
    'steps'           => (string)$recipe['steps'],
    'url'             => (string)$recipe['url'],
    'ingredients'     => $ingredients,
]);
