<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/import.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireRoleJson('editor');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$action = (string)($input['action'] ?? 'fetch');

try {
    $pdo = db();

    if ($action === 'learn') {
        $choices = [];
        foreach ((array)($input['choices'] ?? []) as $c) {
            if (is_array($c) && isset($c['alias'])) {
                $choices[(string)$c['alias']] = isset($c['ingredient_id']) ? (int)$c['ingredient_id'] : null;
            }
        }
        importLearn($pdo, $choices);
        jsonOut(['ok' => true]);
    }

    if ($action === 'paste') {
        $pasted = importPasted(
            $pdo,
            trim((string)($input['name'] ?? '')),
            (string)($input['ingredients'] ?? ''),
            (string)($input['steps'] ?? ''),
            (int)($input['servings'] ?? 4),
            trim((string)($input['url'] ?? ''))
        );
        if ($pasted['lines'] === []) {
            jsonOut(['error' => 'Plak minstens één ingrediënt.'], 422);
        }
        jsonOut(['ok' => true] + $pasted);
    }

    $recipe = importRecipe($pdo, trim((string)($input['url'] ?? '')));
} catch (Throwable $e) {
    jsonOut(['error' => 'Importeren mislukt'], 500);
}

if ($recipe === null) {
    // Geen harde fout: de pagina biedt dan plakken aan.
    jsonOut(['ok' => false, 'paste' => true,
             'message' => 'Deze site laat zich niet automatisch uitlezen. Plak de ingrediënten zelf.'], 200);
}

jsonOut(['ok' => true] + $recipe);
