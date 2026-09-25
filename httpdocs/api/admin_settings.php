<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/settings.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireRoleJson('admin');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$value = max(1, min(20, (int)($input['default_servings'] ?? 3)));
$days  = max(1, min(7, (int)($input['planning_days'] ?? 7)));

try {
    $pdo = db();
    setSetting($pdo, SETTING_DEFAULT_SERVINGS, (string)$value);
    setSetting($pdo, SETTING_PLANNING_DAYS, (string)$days);
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut([
    'ok'            => true,
    'notice'        => 'Instellingen opgeslagen.',
    'value'         => $value,
    'planning_days' => $days,
]);
