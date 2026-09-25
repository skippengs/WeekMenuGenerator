<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

startSession();

if (currentUser() !== null) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Sessie verlopen. Probeer het opnieuw.';
    } else {
        $error = attemptLogin((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($error === null) {
            header('Location: index.php');
            exit;
        }
        // Kleine vertraging, zodat blind proberen weinig zin heeft.
        usleep(400000);
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inloggen</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
</head>
<body>
<main class="wrap login-wrap">
    <form class="card" method="post" action="login.php">
        <h2>Inloggen</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= esc($error) ?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">

        <div class="field">
            <label for="un">Gebruikersnaam</label>
            <input type="text" id="un" name="username" required autofocus autocomplete="username"
                   autocapitalize="none" value="<?= esc((string)($_POST['username'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="pw">Wachtwoord</label>
            <input type="password" id="pw" name="password" required autocomplete="current-password">
        </div>

        <button class="btn btn-primary" type="submit">Inloggen</button>
        <p class="field-hint" style="margin-top:14px">
            <a href="index.php">Terug naar het weekmenu</a>
        </p>
    </form>
</main>
</body>
</html>
