<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/admin_helpers.php';

/*
 * Gebruikers aanmaken, rol wijzigen, wachtwoord opnieuw zetten, wissen.
 * Welke van de vier bepaalt 'action' in de payload.
 */

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireRoleJson('admin');

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$action   = (string)($input['action'] ?? '');
$id       = (int)($input['id'] ?? 0);
$role     = (string)($input['role'] ?? '');
$password = (string)($input['password'] ?? '');
$selfId   = currentUser()['id'];

if (in_array($action, ['create', 'password'], true) && mb_strlen($password) < 8) {
    jsonOut(['error' => 'Kies een wachtwoord van minstens 8 tekens.'], 422);
}
if (in_array($action, ['create', 'role'], true) && !isset(ROLES[$role])) {
    jsonOut(['error' => 'Onbekende rol.'], 422);
}

try {
    $pdo = db();

    // Er moet altijd een beheerder overblijven, anders kan niemand meer
    // gebruikers beheren.
    $isLastAdmin = static function (int $userId) use ($pdo): bool {
        $admins = $pdo->query("SELECT id FROM {app_user} WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        return count($admins) === 1 && (int)$admins[0] === $userId;
    };

    switch ($action) {
        case 'create':
            $username = trim((string)($input['username'] ?? ''));
            if ($username === '' || mb_strlen($username) > 60) {
                jsonOut(['error' => 'Kies een gebruikersnaam.'], 422);
            }
            $exists = $pdo->prepare('SELECT COUNT(*) FROM {app_user} WHERE username = ?');
            $exists->execute([$username]);
            if ((int)$exists->fetchColumn() > 0) {
                jsonOut(['error' => 'Die gebruikersnaam bestaat al.'], 422);
            }
            $pdo->prepare('INSERT INTO {app_user} (username, password_hash, role) VALUES (?, ?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            $notice = 'Gebruiker ' . $username . ' aangemaakt.';
            break;

        case 'role':
            if ($role !== 'admin' && $isLastAdmin($id)) {
                jsonOut(['error' => 'Dit is de laatste beheerder.'], 422);
            }
            $pdo->prepare('UPDATE {app_user} SET role = ? WHERE id = ?')->execute([$role, $id]);
            $notice = 'Rol aangepast.';
            break;

        case 'password':
            $pdo->prepare('UPDATE {app_user} SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            $notice = 'Wachtwoord aangepast.';
            break;

        case 'delete':
            if ($id === $selfId) {
                jsonOut(['error' => 'Je kunt jezelf niet wissen.'], 422);
            }
            if ($isLastAdmin($id)) {
                jsonOut(['error' => 'Dit is de laatste beheerder.'], 422);
            }
            $pdo->prepare('DELETE FROM {app_user} WHERE id = ?')->execute([$id]);
            $notice = 'Gebruiker gewist.';
            break;

        default:
            jsonOut(['error' => 'Onbekende actie'], 422);
    }

    $userTable = renderUserTable($pdo, $selfId);
} catch (Throwable $e) {
    jsonOut(['error' => 'Opslaan mislukt'], 500);
}

jsonOut([
    'ok'         => true,
    'notice'     => $notice,
    'user_table' => $userTable,
]);
