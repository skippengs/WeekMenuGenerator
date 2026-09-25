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

// recipe.preference => [label, vermenigvuldiger in de loting]. Keer, net als
// de voorraad, zodat het naast het recency-gewicht ook echt merkbaar is.
const PREFERENCES = [1 => ['Favoriet', 2.0], 0 => ['Normaal', 1.0], -1 => ['Zelden', 0.3]];

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

/**
 * Wanneer stond een gerecht vorige keer op tafel, als tekst.
 * $from is de dag waar je vanaf rekent: vandaag, of de dag in het menu.
 * Kort is voor in een tabelcel.
 */
function lastEatenText(?string $date, bool $short = false, string $from = 'today'): string
{
    if ($date === null || $date === '') {
        return $short ? '-' : 'Nog nooit eerder op het menu.';
    }

    $then = new DateTimeImmutable($date);
    $when = $then->format($then->format('Y') === date('Y') ? 'j M' : 'j M Y');
    if ($short) {
        return $when;
    }

    $days = (int)$then->diff(new DateTimeImmutable($from))->days;
    $ago  = match (true) {
        $days === 1 => '1 dag',
        $days < 14  => $days . ' dagen',
        $days < 63  => intdiv($days, 7) . ' weken',
        default     => intdiv($days, 30) . ' maanden',
    };

    return 'Vorige keer: ' . $when . ', ' . $ago . ' eerder.';
}

/** Zelfde als dayDate(), maar als Y-m-d om mee te rekenen. */
function dayDateIso(string $weekStart, int $dayIndex): string
{
    return (new DateTimeImmutable($weekStart))->modify('+' . $dayIndex . ' days')->format('Y-m-d');
}

const MONTH_SHORT = [1 => 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

/** recipe.season ("10,11,12,1") als lijst maanden; leeg = het hele jaar. */
function seasonMonths(?string $season): array
{
    $months = array_filter(
        array_map('intval', explode(',', (string)$season)),
        static fn(int $m): bool => $m >= 1 && $m <= 12
    );
    $months = array_values(array_unique($months));
    sort($months);

    return count($months) === 12 ? [] : $months;
}

/** Terug naar de kolomwaarde; hele jaar wordt NULL. */
function seasonValue(array $months): ?string
{
    $months = seasonMonths(implode(',', $months));
    return $months === [] ? null : implode(',', $months);
}

/** Kort label voor in de tabel: "okt-mrt" als het aaneengesloten is. */
function seasonLabel(?string $season): string
{
    $months = seasonMonths($season);
    if ($months === []) {
        return '';
    }

    // Zoek de maand waar het seizoen begint: de eerste waarvan de vorige
    // maand er niet bij hoort. Loopt het rond, dan is het één blok.
    $in    = array_flip($months);
    $start = null;
    foreach ($months as $m) {
        if (!isset($in[$m === 1 ? 12 : $m - 1])) {
            if ($start !== null) {
                return 'seizoen';   // twee losse blokken
            }
            $start = $m;
        }
    }
    $end = ($start + count($months) - 2) % 12 + 1;

    return $start === $end ? MONTH_SHORT[$start] : MONTH_SHORT[$start] . '-' . MONTH_SHORT[$end];
}
