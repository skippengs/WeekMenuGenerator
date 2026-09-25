<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/settings.php';
require __DIR__ . '/../inc/deals.php';
require __DIR__ . '/../inc/push.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireRoleJson('editor');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$weekId = isset($input['week_id']) ? (int)$input['week_id'] : 0;
$locked = !empty($input['locked']);

if ($weekId <= 0) {
    jsonOut(['error' => 'Onbekende week'], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT week_start, locked_at FROM {menu_week} WHERE id = ?');
    $stmt->execute([$weekId]);
    $before = $stmt->fetch();
    if (!$before) {
        jsonOut(['error' => 'Onbekende week'], 422);
    }

    $pdo->prepare('UPDATE {menu_week} SET locked_at = ? WHERE id = ?')
        ->execute([$locked ? date('Y-m-d H:i:s') : null, $weekId]);

    // Legt de kortingen van dit moment vast zodra de week op slot gaat.
    if ($locked) {
        snapshotWeekDeals($pdo, $weekId);
    }

    // Beheerders horen het als een verstuurde week weer open gaat: wat in
    // Bring staat klopt dan misschien niet meer met het menu.
    if (!$locked && $before['locked_at'] !== null) {
        notifyUsers($pdo, 'admin', [
            'title' => 'Week ontgrendeld',
            'body'  => currentUser()['username'] . ' heeft ' . weekLabel($before['week_start'])
                     . ' weer opengezet. Wat in Bring staat klopt misschien niet meer.',
            'url'   => 'index.php?week=' . $before['week_start'],
        ], currentUser()['id']);
    }
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut(['ok' => true, 'locked' => $locked]);
