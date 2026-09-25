<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Back-up van alle tabellen als .sql, terug te zetten via phpMyAdmin.
 * Alleen voor beheerders: er staan ook de wachtwoord-hashes in.
 */

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

startSession();
requireRole('admin');

$pdo = db();

// Het voorvoegsel mag alleen letters, cijfers en _ bevatten (install.php);
// de _ is in LIKE een jokerteken, dus die escapen.
$like   = str_replace('_', '\_', DB_PREFIX) . '%';
// SHOW kan geen placeholders aan, vandaar quote().
$tables = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($like))->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="weekmenu-' . date('Y-m-d') . '.sql"');

echo "-- Weekmenu back-up, " . date('Y-m-d H:i') . "\n";
echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $table) {
    // Tabelnamen komen uit SHOW TABLES, niet van de bezoeker.
    $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    echo "DROP TABLE IF EXISTS `$table`;\n$create;\n\n";

    foreach ($pdo->query('SELECT * FROM `' . $table . '`', PDO::FETCH_ASSOC) as $row) {
        $values = array_map(
            static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
            array_values($row)
        );
        echo 'INSERT INTO `' . $table . '` VALUES (' . implode(', ', $values) . ");\n";
    }
    echo "\n";
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
