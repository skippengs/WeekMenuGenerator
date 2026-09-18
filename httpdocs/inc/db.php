<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);

        // Op de server geen details tonen, lokaal juist wel.
        if (defined('DEBUG') && DEBUG) {
            exit('Geen verbinding met de database: ' . $e->getMessage());
        }

        exit('Geen verbinding met de database. Controleer inc/config.php.');
    }

    return $pdo;
}
