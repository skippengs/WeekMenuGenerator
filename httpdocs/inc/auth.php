<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/*
 * Gebruikers en rollen. Elke rol mag alles wat de rol eronder mag:
 *
 *   reader  kijken, net als zonder inloggen, maar met eigen meldingen
 *   editor  afvinken, menu maken en wijzigen, naar Bring, recepten
 *   admin   instellingen, voorraadlijst, kortingen, gebruikers
 *
 * Zonder inloggen kun je ook kijken; bring.php heeft sowieso geen login.
 */

const ROLES = ['reader' => 1, 'editor' => 2, 'admin' => 3];

const ROLE_LABELS = ['reader' => 'Lezer', 'editor' => 'Bewerker', 'admin' => 'Beheerder'];

// Zoveel mislukte pogingen per ip-adres binnen zoveel minuten, daarna even niet.
const LOGIN_MAX_FAILURES   = 5;
const LOGIN_WINDOW_MINUTES = 15;

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

/**
 * De ingelogde gebruiker, elke request vers uit de database. Zo werkt een
 * andere rol of een verwijderd account meteen, niet pas na uitloggen.
 */
function currentUser(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    $user = null;
    $id   = (int)($_SESSION['user_id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('SELECT id, username, role FROM {app_user} WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: null;
        if ($user === null) {
            unset($_SESSION['user_id']);
        } else {
            $user['id'] = (int)$user['id'];
        }
    }

    return $user;
}

function hasRole(string $role): bool
{
    $user = currentUser();
    return $user !== null && (ROLES[$user['role']] ?? 0) >= ROLES[$role];
}

/**
 * Voor de api-bestanden: JSON terug in plaats van naar de inlogpagina.
 * 401 als je niet ingelogd bent, 403 als je rol te laag is.
 */
function requireRoleJson(string $role): void
{
    if (currentUser() === null) {
        jsonOut(['error' => 'Log in om het weekmenu te wijzigen.'], 401);
    }
    if (!hasRole($role)) {
        jsonOut(['error' => 'Dat mag jouw account niet.'], 403);
    }
}

function requireRole(string $role): void
{
    if (currentUser() === null) {
        header('Location: login.php');
        exit;
    }
    if (!hasRole($role)) {
        http_response_code(403);
        exit('Dat mag jouw account niet. <a href="index.php">Terug naar het weekmenu</a>');
    }
}

function clientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function loginBlocked(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM {login_attempt}
          WHERE ip = ? AND attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE'
    );
    $stmt->execute([clientIp()]);
    return (int)$stmt->fetchColumn() >= LOGIN_MAX_FAILURES;
}

/** Geeft null bij succes, anders de foutmelding voor het formulier. */
function attemptLogin(string $username, string $password): ?string
{
    $pdo = db();

    if (loginBlocked($pdo)) {
        return 'Te veel mislukte pogingen. Probeer het over een kwartier opnieuw.';
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM {app_user} WHERE username = ?');
    $stmt->execute([trim($username)]);
    $row = $stmt->fetch();

    // Ook bij een onbekende naam evenveel rekenwerk, zodat je aan de tijd
    // niet kunt zien of een gebruikersnaam bestaat.
    $hash = $row ? $row['password_hash'] : password_hash($password, PASSWORD_DEFAULT);
    if (!$row || !password_verify($password, $hash)) {
        $pdo->prepare('INSERT INTO {login_attempt} (ip, attempted_at) VALUES (?, NOW())')->execute([clientIp()]);
        $pdo->exec('DELETE FROM {login_attempt} WHERE attempted_at < NOW() - INTERVAL 1 DAY');
        return 'Onjuiste gebruikersnaam of wachtwoord.';
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE {app_user} SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }

    $pdo->prepare('DELETE FROM {login_attempt} WHERE ip = ?')->execute([clientIp()]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];
    return null;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
