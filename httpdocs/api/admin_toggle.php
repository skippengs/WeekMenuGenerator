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
    $pdo->prepare('UPDATE {recipe} SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);

    $stmt = $pdo->prepare('SELECT is_active FROM {recipe} WHERE id = ?');
    $stmt->execute([$id]);
    $isActive = $stmt->fetchColumn();

    if ($isActive === false) {
        jsonOut(['error' => 'Dat recept bestaat niet meer.'], 404);
    }
} catch (Throwable $e) {
    jsonOut(['error' => 'Aan- of uitzetten mislukt'], 500);
}

jsonOut([
    'ok'        => true,
    'notice'    => 'Aan- of uitgezet.',
    'id'        => $id,
    'is_active' => (int)$isActive,
]);
