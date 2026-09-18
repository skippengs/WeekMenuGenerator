<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

const DAY_NAMES = ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'];

const CATEGORIES = [
    'aardappel' => 'Aardappel',
    'pasta'     => 'Pasta',
    'rijst'     => 'Rijst',
    'noedels'   => 'Noedels / mie',
    'stamppot'  => 'Stamppot',
    'oven'      => 'Ovenschotel',
    'soep'      => 'Soep',
    'wraps'     => 'Wraps / brood',
    'vlees'     => 'Vleesgerecht',
    'vis'       => 'Vis',
    'bonen'     => 'Bonen / peulvruchten',
    'overig'    => 'Overig',
];

const EFFORTS = [1 => 'Snel (< 25 min)', 2 => 'Normaal', 3 => 'Uitgebreid'];

function esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Maandag van de week waar $date in valt.
 * Onzin in de URL (?week=kaas) levert gewoon deze week op, geen foutpagina.
 */
function weekStart(?string $date = null): string
{
    try {
        $d = new DateTimeImmutable($date ?? 'today');
    } catch (Throwable $e) {
        $d = new DateTimeImmutable('today');
    }

    return $d->modify('monday this week')->format('Y-m-d');
}

function weekLabel(string $weekStart): string
{
    $start = new DateTimeImmutable($weekStart);
    $end   = $start->modify('+6 days');
    return $start->format('j M') . ' t/m ' . $end->format('j M Y');
}

function dayDate(string $weekStart, int $dayIndex): string
{
    return (new DateTimeImmutable($weekStart))->modify('+' . $dayIndex . ' days')->format('j M');
}

function isWeekendDay(int $dayIndex): bool
{
    return $dayIndex === 5 || $dayIndex === 6;
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Leest de JSON body van een fetch()-request. */
function jsonIn(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function checkCsrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}
