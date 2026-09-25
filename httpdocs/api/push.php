<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/settings.php';
require __DIR__ . '/../inc/push.php';

/*
 * Meldingen aan- en uitzetten voor dit apparaat, of een testmelding naar
 * je eigen apparaten. Elke ingelogde gebruiker mag dit, ook een lezer.
 */

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireRoleJson('reader');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$action   = (string)($input['action'] ?? '');
$endpoint = (string)($input['endpoint'] ?? '');
$p256dh   = (string)($input['p256dh'] ?? '');
$auth     = (string)($input['auth'] ?? '');
$userId   = currentUser()['id'];

try {
    $pdo = db();

    switch ($action) {
        case 'subscribe':
            if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 500
                || strlen(b64urlDecode($p256dh)) !== 65 || strlen(b64urlDecode($auth)) !== 16) {
                jsonOut(['error' => 'Ongeldig abonnement'], 422);
            }
            $pdo->prepare(
                'INSERT INTO {push_subscription} (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)'
            )->execute([$userId, $endpoint, $p256dh, $auth]);

            // De push-diensten willen weten van wie de meldingen komen; het
            // adres van de site is daar goed genoeg voor. Cron weet dat niet
            // zelf, dus hier onthouden.
            if (!empty($_SERVER['HTTPS'])) {
                $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/\\');
                setSetting($pdo, PUSH_SETTING_SITE, 'https://' . $_SERVER['HTTP_HOST'] . $dir . '/');
            }
            jsonOut(['ok' => true, 'notice' => 'Meldingen staan aan op dit apparaat.']);

        case 'unsubscribe':
            $pdo->prepare('DELETE FROM {push_subscription} WHERE endpoint = ?')->execute([$endpoint]);
            jsonOut(['ok' => true, 'notice' => 'Meldingen staan uit op dit apparaat.']);

        case 'test':
            $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM {push_subscription} WHERE user_id = ?');
            $stmt->execute([$userId]);
            $sent = 0;
            foreach ($stmt as $sub) {
                $status = pushSend($pdo, $sub, ['title' => 'Weekmenu', 'body' => 'Meldingen werken.', 'url' => 'index.php']);
                $sent += $status >= 200 && $status < 300 ? 1 : 0;
            }
            jsonOut(['ok' => true, 'notice' => 'Testmelding verstuurd naar ' . $sent . ' apparaat/apparaten.']);
    }
} catch (Throwable $e) {
    jsonOut(['error' => 'Meldingen instellen mislukt'], 500);
}

jsonOut(['error' => 'Onbekende actie'], 422);
