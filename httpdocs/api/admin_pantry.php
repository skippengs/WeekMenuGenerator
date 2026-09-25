<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireRoleJson('admin');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$checked = array_map('intval', (array)($input['pantry'] ?? []));

try {
    $pdo = db();
    $pdo->exec('UPDATE {ingredient} SET is_pantry_item = 0');

    if ($checked !== []) {
        $in = implode(',', array_fill(0, count($checked), '?'));
        $pdo->prepare("UPDATE {ingredient} SET is_pantry_item = 1 WHERE id IN ($in)")
            ->execute($checked);
    }
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut([
    'ok'     => true,
    'notice' => 'Voorraadlijst opgeslagen.',
]);
