<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

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

function isAdmin(): bool
{
    return !empty($_SESSION['is_admin']);
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        header('Location: login.php');
        exit;
    }
}

function attemptLogin(string $password): bool
{
    // Vaste-tijd vergelijking, zodat je het wachtwoord niet kunt raden
    // door te meten hoe lang een poging duurt.
    if (hash_equals(ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
