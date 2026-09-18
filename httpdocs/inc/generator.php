<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/**
 * Laadt alle actieve recepten en verrijkt ze met:
 *   weeks_since  - hoeveel weken geleden stond dit op tafel (null = nooit)
 *   pantry_hits  - hoeveel aangevinkte voorraad-ingredienten dit recept gebruikt
 */
function loadPool(PDO $pdo, array $pantryIds, string $referenceWeek): array
{
    $recipes = $pdo->query(
        'SELECT id, name, category, effort, weekend_only, notes, url
           FROM {recipe}
          WHERE is_active = 1'
    )->fetchAll();

    // Hoe dicht zit elk recept bij vandaag?
    //
    // Niet "wanneer voor het laatst", maar de kortste afstand tot nu -
    // vooruit of achteruit. Plan je in week 40 al iets in, dan moet dat
    // ook meetellen, anders zou die verre datum verbergen dat je het
    // vorige week ook al at.
    $distance = [];
    $rows = $pdo->prepare(
        'SELECT me.recipe_id, MIN(ABS(DATEDIFF(mw.week_start, ?))) AS day_distance
           FROM {menu_entry} me
           JOIN {menu_week} mw ON mw.id = me.week_id
          WHERE me.recipe_id IS NOT NULL
          GROUP BY me.recipe_id'
    );
    $rows->execute([$referenceWeek]);

    foreach ($rows as $r) {
        $distance[(int)$r['recipe_id']] = (int)$r['day_distance'];
    }

    // Hoeveel kern-ingredienten van elk recept heb je in huis?
    $hits = [];
    $pantryIds = array_values(array_unique(array_map('intval', $pantryIds)));
    if ($pantryIds !== []) {
        $placeholders = implode(',', array_fill(0, count($pantryIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT recipe_id, COUNT(*) AS hits
               FROM {recipe_ingredient}
              WHERE is_key = 1 AND ingredient_id IN ($placeholders)
              GROUP BY recipe_id"
        );
        $stmt->execute($pantryIds);
        foreach ($stmt as $r) {
            $hits[(int)$r['recipe_id']] = (int)$r['hits'];
        }
    }

    foreach ($recipes as &$r) {
        $r['id']           = (int)$r['id'];
        $r['effort']       = (int)$r['effort'];
        $r['weekend_only'] = (int)$r['weekend_only'];

        $r['weeks_since'] = isset($distance[$r['id']])
            ? (int)floor($distance[$r['id']] / 7)
            : null;

        $r['pantry_hits'] = $hits[$r['id']] ?? 0;
    }
    unset($r);

    return $recipes;
}

/**
 * Hoe zwaar weegt dit recept in de loterij?
 * Langer geleden = zwaarder. In huis = zwaarder. Uitgebreid koken telt
 * alleen zwaar in het weekend.
 */
function recipeWeight(array $r, bool $isWeekend): float
{
    $base = $r['weeks_since'] === null
        ? NEVER_SERVED_WEIGHT
        : min((float)$r['weeks_since'], MAX_RECENCY_WEIGHT);

    $w = $base + 1.0;

    // Keer, niet plus. Bij optellen viel de voorraad in het niet bij het
    // recency-gewicht van een recept dat al maanden niet langs was.
    if ($r['pantry_hits'] > 0) {
        $w *= 1.0 + ($r['pantry_hits'] * PANTRY_BOOST);
    }

    if ($r['effort'] >= 3) {
        $w *= $isWeekend ? 1.4 : 0.30;   // doordeweeks geen uurtje in de keuken
    } elseif ($r['effort'] === 1 && !$isWeekend) {
        $w *= 1.15;                      // snelle hap scoort op een werkdag
    }

    return max($w, 0.5);
}

/** Trekt een recept uit de lijst, kans evenredig aan het gewicht. */
function weightedPick(array $candidates, bool $isWeekend): ?array
{
    if ($candidates === []) {
        return null;
    }

    $weights = [];
    $total   = 0.0;
    foreach ($candidates as $i => $c) {
        $w = recipeWeight($c, $isWeekend);
        $weights[$i] = $w;
        $total += $w;
    }
    if ($total <= 0.0) {
        return $candidates[array_rand($candidates)];
    }

    $roll = mt_rand(1, 1000000) / 1000000 * $total;
    $acc  = 0.0;
    foreach ($candidates as $i => $c) {
        $acc += $weights[$i];
        if ($roll <= $acc) {
            return $c;
        }
    }

    return $candidates[array_key_last($candidates)];
}

/**
 * Kiest een recept voor een dag. Begint streng en laat stap voor stap
 * regels los, zodat je ook met 12 recepten nog een week gevuld krijgt.
 */
function pickForDay(array $pool, int $dayIndex, array $usedIds, array $usedCats): ?array
{
    $isWeekend = isWeekendDay($dayIndex);

    $notUsed   = static fn(array $r): bool => !in_array($r['id'], $usedIds, true);
    $dayOk     = static fn(array $r): bool => $isWeekend || $r['weekend_only'] === 0;
    $cooledOff = static fn(array $r): bool => $r['weeks_since'] === null || $r['weeks_since'] >= COOLDOWN_WEEKS;
    $newCat    = static fn(array $r): bool => !in_array($r['category'], $usedCats, true);

    $stages = [
        static fn(array $r): bool => $notUsed($r) && $dayOk($r) && $cooledOff($r) && $newCat($r),
        static fn(array $r): bool => $notUsed($r) && $dayOk($r) && $cooledOff($r),
        static fn(array $r): bool => $notUsed($r) && $dayOk($r),
        static fn(array $r): bool => $notUsed($r),
        static fn(array $r): bool => true,
    ];

    foreach ($stages as $filter) {
        $candidates = array_values(array_filter($pool, $filter));
        if ($candidates !== []) {
            return weightedPick($candidates, $isWeekend);
        }
    }

    return null;
}

/**
 * Bouwt een hele week en slaat hem op. Bestaat de week al, dan wordt
 * hij overschreven. Geeft het id van de week terug.
 */
function generateWeek(PDO $pdo, string $weekStart, array $pantryIds): int
{
    $pool     = loadPool($pdo, $pantryIds, $weekStart);
    $usedIds  = [];
    $usedCats = [];
    $picks    = [];

    for ($day = 0; $day < 7; $day++) {
        if ($day === JUNK_DAY_INDEX) {
            $picks[$day] = null;
            continue;
        }

        $pick = pickForDay($pool, $day, $usedIds, $usedCats);
        if ($pick !== null) {
            $usedIds[]   = $pick['id'];
            $usedCats[]  = $pick['category'];
            $picks[$day] = $pick['id'];
        } else {
            $picks[$day] = null;
        }
    }

    $pantryJson = json_encode(array_values(array_map('intval', $pantryIds)));

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO {menu_week} (week_start, pantry_json)
                  VALUES (?, ?)
             ON DUPLICATE KEY UPDATE pantry_json = VALUES(pantry_json), created_at = NOW()'
        )->execute([$weekStart, $pantryJson]);

        $stmt = $pdo->prepare('SELECT id FROM {menu_week} WHERE week_start = ?');
        $stmt->execute([$weekStart]);
        $weekId = (int)$stmt->fetchColumn();

        $pdo->prepare('DELETE FROM {menu_entry} WHERE week_id = ?')->execute([$weekId]);

        $insert = $pdo->prepare(
            'INSERT INTO {menu_entry} (week_id, day_index, recipe_id, is_junkfood)
                  VALUES (?, ?, ?, ?)'
        );
        foreach ($picks as $day => $recipeId) {
            $insert->execute([$weekId, $day, $recipeId, $day === JUNK_DAY_INDEX ? 1 : 0]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $weekId;
}

/** Gooit een enkele dag opnieuw, met dezelfde voorraad als bij het genereren. */
function rerollDay(PDO $pdo, int $weekId, int $dayIndex): ?array
{
    if ($dayIndex === JUNK_DAY_INDEX) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT week_start, pantry_json FROM {menu_week} WHERE id = ?');
    $stmt->execute([$weekId]);
    $week = $stmt->fetch();
    if (!$week) {
        return null;
    }

    $pantryIds = is_string($week['pantry_json'])
        ? (json_decode($week['pantry_json'], true) ?: [])
        : [];

    $pool = loadPool($pdo, $pantryIds, (string)$week['week_start']);
    $byId = array_column($pool, null, 'id');

    // Alles wat deze week al op het menu staat blijft buiten beschouwing.
    $stmt = $pdo->prepare(
        'SELECT day_index, recipe_id FROM {menu_entry}
          WHERE week_id = ? AND recipe_id IS NOT NULL'
    );
    $stmt->execute([$weekId]);

    $usedIds   = [];
    $usedCats  = [];
    $currentId = null;

    foreach ($stmt as $row) {
        $rid = (int)$row['recipe_id'];

        if ((int)$row['day_index'] === $dayIndex) {
            // Het gerecht dat er nu staat sluiten we uit, anders levert
            // "ander gerecht" soms precies hetzelfde op. De categorie
            // blokkeren we niet: die plek komt juist vrij.
            $currentId = $rid;
            continue;
        }

        $usedIds[] = $rid;
        if (isset($byId[$rid])) {
            $usedCats[] = $byId[$rid]['category'];
        }
    }

    if ($currentId !== null) {
        $usedIds[] = $currentId;
    }

    $pick = pickForDay($pool, $dayIndex, $usedIds, $usedCats);
    if ($pick === null) {
        return null;
    }

    $pdo->prepare(
        'UPDATE {menu_entry} SET recipe_id = ? WHERE week_id = ? AND day_index = ?'
    )->execute([$pick['id'], $weekId, $dayIndex]);

    return $pick;
}

/** Haalt een opgeslagen week op als array van 7 dagen. */
function loadWeek(PDO $pdo, string $weekStart): ?array
{
    $stmt = $pdo->prepare('SELECT id, week_start, pantry_json FROM {menu_week} WHERE week_start = ?');
    $stmt->execute([$weekStart]);
    $week = $stmt->fetch();
    if (!$week) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT me.day_index, me.is_junkfood, r.id, r.name, r.category, r.effort, r.notes, r.url
           FROM {menu_entry} me
      LEFT JOIN {recipe} r ON r.id = me.recipe_id
          WHERE me.week_id = ?
          ORDER BY me.day_index'
    );
    $stmt->execute([(int)$week['id']]);

    $days = [];
    foreach ($stmt as $row) {
        $days[(int)$row['day_index']] = $row;
    }

    return [
        'id'         => (int)$week['id'],
        'week_start' => $week['week_start'],
        'pantry'     => json_decode((string)$week['pantry_json'], true) ?: [],
        'days'       => $days,
    ];
}

/**
 * Boodschappenlijst: alle kern-ingredienten van de gekozen recepten,
 * minus wat je had aangevinkt als "heb ik al".
 */
function shoppingList(PDO $pdo, int $weekId): array
{
    $stmt = $pdo->prepare('SELECT pantry_json FROM {menu_week} WHERE id = ?');
    $stmt->execute([$weekId]);
    $pantryIds = array_map('intval', json_decode((string)$stmt->fetchColumn(), true) ?: []);

    $stmt = $pdo->prepare(
        'SELECT DISTINCT i.id, i.name, i.category
           FROM {menu_entry} me
           JOIN {recipe_ingredient} ri ON ri.recipe_id = me.recipe_id
           JOIN {ingredient} i ON i.id = ri.ingredient_id
          WHERE me.week_id = ?
          ORDER BY i.category, i.name'
    );
    $stmt->execute([$weekId]);

    $list = [];
    foreach ($stmt as $row) {
        if (in_array((int)$row['id'], $pantryIds, true)) {
            continue;   // heb je al in huis
        }
        $list[$row['category']][] = $row['name'];
    }

    return $list;
}
