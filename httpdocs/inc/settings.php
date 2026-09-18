<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/*
 * Kleine sleutel-waardeopslag voor instellingen die je in het admin
 * paneel kunt wijzigen, zoals het standaard aantal personen.
 */

const SETTING_DEFAULT_SERVINGS = 'default_servings';

function getSetting(PDO $pdo, string $name, string $fallback = ''): string
{
    static $cache = [];

    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    $stmt = $pdo->prepare('SELECT value FROM {setting} WHERE name = ?');
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();

    $cache[$name] = $value === false ? $fallback : (string)$value;
    return $cache[$name];
}

function setSetting(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare(
        'INSERT INTO {setting} (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$name, $value]);
}

/** Standaard aantal personen, zoals ingesteld in het admin paneel. */
function defaultServings(PDO $pdo): int
{
    $value = (int)getSetting($pdo, SETTING_DEFAULT_SERVINGS, '3');
    return max(1, min(20, $value ?: 3));
}
