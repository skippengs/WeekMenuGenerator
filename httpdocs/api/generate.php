<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/generator.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

// Datum normaliseren naar de maandag van die week.
try {
    $week = weekStart(isset($input['week_start']) ? (string)$input['week_start'] : null);
} catch (Throwable $e) {
    jsonOut(['error' => 'Ongeldige datum'], 422);
}

$pantry = [];
if (isset($input['pantry']) && is_array($input['pantry'])) {
    foreach ($input['pantry'] as $id) {
        if (is_numeric($id)) {
            $pantry[] = (int)$id;
        }
    }
}

try {
    $pdo = db();

    if ((int)$pdo->query('SELECT COUNT(*) FROM {recipe} WHERE is_active = 1')->fetchColumn() === 0) {
        jsonOut(['error' => 'Er staan nog geen recepten in de database.'], 422);
    }

    $weekId = generateWeek($pdo, $week, $pantry);
} catch (Throwable $e) {
    jsonOut(['error' => 'Genereren mislukt: ' . $e->getMessage()], 500);
}

jsonOut(['ok' => true, 'week_id' => $weekId, 'week_start' => $week]);
