<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/generator.php';

startSession();
$pdo = db();

$current  = weekStart($_GET['week'] ?? null);
$week     = loadWeek($pdo, $current);
$shopping = $week ? shoppingList($pdo, $week['id']) : [];

$prevWeek = (new DateTimeImmutable($current))->modify('-7 days')->format('Y-m-d');
$nextWeek = (new DateTimeImmutable($current))->modify('+7 days')->format('Y-m-d');

// Voorraaditems voor het popup-venster, gegroepeerd.
$pantryGroups = [];
$stmt = $pdo->query(
    'SELECT id, name, category FROM ingredient
      WHERE is_pantry_item = 1
      ORDER BY category, name'
);
foreach ($stmt as $row) {
    $pantryGroups[$row['category']][] = $row;
}

const PANTRY_GROUP_LABELS = [
    'vlees'   => 'Vlees & vis',
    'basis'   => 'Basis',
    'groente' => 'Groente',
    'rest'    => 'Zuivel & rest',
];

// Vink standaard aan wat je de vorige keer ook aanvinkte.
$defaultPantry = $week['pantry'] ?? [];
if ($defaultPantry === []) {
    $last = $pdo->query('SELECT pantry_json FROM menu_week ORDER BY week_start DESC LIMIT 1')->fetchColumn();
    $defaultPantry = is_string($last) ? (json_decode($last, true) ?: []) : [];
}
$defaultPantry = array_map('intval', $defaultPantry);

$recipeCount = (int)$pdo->query('SELECT COUNT(*) FROM recipe WHERE is_active = 1')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weekmenu</title>
<meta name="theme-color" content="#1f6f4f">
<link rel="stylesheet" href="assets/app.css">
<link rel="manifest" href="manifest.json">
</head>
<body>

<header class="topbar">
    <div class="wrap topbar-inner">
        <h1>Weekmenu</h1>
        <nav class="topnav">
            <a href="admin.php">Recepten beheren</a>
        </nav>
    </div>
</header>

<main class="wrap">

    <div class="weeknav">
        <a class="btn btn-ghost" href="?week=<?= esc($prevWeek) ?>" title="Vorige week">&larr;</a>
        <div class="weeknav-label">
            <strong><?= esc(weekLabel($current)) ?></strong>
            <?php if ($current === weekStart()): ?><span class="chip chip-now">deze week</span><?php endif; ?>
        </div>
        <a class="btn btn-ghost" href="?week=<?= esc($nextWeek) ?>" title="Volgende week">&rarr;</a>
    </div>

    <?php if ($week === null): ?>

        <section class="empty">
            <p class="empty-text">Voor deze week staat nog geen menu klaar.</p>
            <button class="btn btn-primary btn-big" data-open-pantry>Genereer weekmenu</button>
            <?php if ($recipeCount < 10): ?>
                <p class="hint">
                    Er zijn nu <?= $recipeCount ?> recepten. Voeg er een paar toe via
                    <a href="admin.php">Recepten beheren</a> voor meer afwisseling.
                </p>
            <?php endif; ?>
        </section>

    <?php else: ?>

        <section class="days" data-week-id="<?= (int)$week['id'] ?>">
            <?php foreach (DAY_NAMES as $i => $dayName): ?>
                <?php
                $entry      = $week['days'][$i] ?? null;
                $isJunk     = $i === JUNK_DAY_INDEX;
                $hasRecipe  = $entry && $entry['id'] !== null;
                ?>
                <article class="day <?= $isJunk ? 'day-junk' : '' ?>" data-day="<?= $i ?>">
                    <div class="day-head">
                        <span class="day-name"><?= esc($dayName) ?></span>
                        <span class="day-date"><?= esc(dayDate($current, $i)) ?></span>
                    </div>

                    <div class="day-body">
                        <?php if ($isJunk): ?>
                            <span class="junk-label"><?= esc(JUNK_LABEL) ?></span>
                        <?php elseif ($hasRecipe): ?>
                            <div class="recipe">
                                <span class="recipe-name" data-field="name"><?= esc($entry['name']) ?></span>
                                <div class="recipe-meta">
                                    <span class="chip" data-field="category">
                                        <?= esc(CATEGORIES[$entry['category']] ?? $entry['category']) ?>
                                    </span>
                                    <span class="chip chip-soft" data-field="effort">
                                        <?= esc(EFFORTS[(int)$entry['effort']] ?? '') ?>
                                    </span>
                                </div>
                                <?php if (!empty($entry['notes'])): ?>
                                    <p class="recipe-note" data-field="notes"><?= esc($entry['notes']) ?></p>
                                <?php else: ?>
                                    <p class="recipe-note is-empty" data-field="notes"></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="recipe-name is-muted" data-field="name">Geen recept gevonden</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$isJunk): ?>
                        <button class="btn btn-reroll" data-reroll="<?= $i ?>" title="Ander gerecht voor deze dag">
                            Ander gerecht
                        </button>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="actions">
            <button class="btn btn-primary" data-open-pantry>Genereer opnieuw</button>
        </div>

        <?php if ($shopping !== []): ?>
            <section class="shopping">
                <h2>Boodschappenlijst</h2>
                <p class="hint">Alles wat de recepten van deze week nodig hebben, minus wat je al in huis had.</p>
                <div class="shopping-groups">
                    <?php foreach ($shopping as $group => $items): ?>
                        <div class="shopping-group">
                            <h3><?= esc(PANTRY_GROUP_LABELS[$group] ?? ucfirst($group)) ?></h3>
                            <ul>
                                <?php foreach ($items as $item): ?>
                                    <li><label><input type="checkbox"> <?= esc($item) ?></label></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php endif; ?>

</main>

<!-- Voorraadvenster ------------------------------------------------ -->
<div class="modal" id="pantryModal" hidden>
    <div class="modal-backdrop" data-close-pantry></div>

    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="pantryTitle">
        <header class="modal-head">
            <h2 id="pantryTitle">Wat heb je al in huis?</h2>
            <button class="modal-x" data-close-pantry aria-label="Sluiten">&times;</button>
        </header>

        <p class="modal-intro">
            Vink aan wat er nog ligt. Recepten die dat gebruiken krijgen voorrang.
            Niets aanvinken mag ook &mdash; dan kiest hij puur op afwisseling.
        </p>

        <div class="modal-body">
            <?php foreach (PANTRY_GROUP_LABELS as $groupKey => $groupLabel): ?>
                <?php if (empty($pantryGroups[$groupKey])) { continue; } ?>
                <fieldset class="pantry-group">
                    <legend><?= esc($groupLabel) ?></legend>
                    <div class="pantry-items">
                        <?php foreach ($pantryGroups[$groupKey] as $item): ?>
                            <label class="pantry-item">
                                <input type="checkbox" name="pantry" value="<?= (int)$item['id'] ?>"
                                    <?= in_array((int)$item['id'], $defaultPantry, true) ? 'checked' : '' ?>>
                                <span><?= esc($item['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
        </div>

        <footer class="modal-foot">
            <button class="btn btn-ghost" data-pantry-clear>Alles uitvinken</button>
            <div class="modal-foot-right">
                <button class="btn btn-ghost" data-close-pantry>Annuleren</button>
                <button class="btn btn-primary" data-pantry-submit>Genereer weekmenu</button>
            </div>
        </footer>
    </div>
</div>

<script>
    window.WEEKMENU = {
        csrf: <?= json_encode(csrfToken()) ?>,
        weekStart: <?= json_encode($current) ?>,
        weekId: <?= json_encode($week['id'] ?? null) ?>
    };
</script>
<script src="assets/app.js"></script>
</body>
</html>
