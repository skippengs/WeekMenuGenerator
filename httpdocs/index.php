<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/generator.php';

startSession();
$pdo = db();

// Kijken mag iedereen; alleen ingelogd kun je iets veranderen.
$mayEdit = isAdmin();

$current  = weekStart($_GET['week'] ?? null);
$week     = loadWeek($pdo, $current);
$shopping = $week ? shoppingList($pdo, $week['id']) : [];

// Restjes: van welke dag is een restjesdag afkomstig, en op welke lege
// dag mag je "restjes van ..." aanklikken.
$leftoverSourceDay  = [];
$leftoverSuggestion = [];
if ($week !== null) {
    foreach ($week['days'] as $di => $d) {
        if (!empty($d['is_leftover']) && $d['id'] !== null) {
            foreach ($week['days'] as $sdi => $sd) {
                if ($sdi < $di && $sd['id'] === $d['id'] && empty($sd['is_leftover'])) {
                    $leftoverSourceDay[$di] = $sdi;
                    break;
                }
            }
        }

        if ($d['id'] !== null && empty($d['is_leftover']) && !empty($d['makes_leftovers'])) {
            $target = leftoverTargetDay($di);
            // Alleen voorstellen op een dag die nog leeg is: staat er al
            // een eigen gerecht gepland, dan zou aanklikken dat overschrijven.
            if ($target !== null && ($week['days'][$target]['id'] ?? null) === null) {
                $leftoverSuggestion[$target] = ['source_day' => $di, 'name' => $d['name']];
            }
        }
    }
}

// Een week die naar Bring is gestuurd staat op slot: het menu ligt vast,
// maar afstrepen tijdens het boodschappen doen moet gewoon kunnen.
$isLocked    = $week !== null && $week['locked_at'] !== null;
$mayEditMenu = $mayEdit && !$isLocked;

$prevWeek = (new DateTimeImmutable($current))->modify('-7 days')->format('Y-m-d');
$nextWeek = (new DateTimeImmutable($current))->modify('+7 days')->format('Y-m-d');

// Voorraaditems voor het popup-venster, gegroepeerd.
$pantryGroups = [];
$stmt = $pdo->query(
    'SELECT id, name, category FROM {ingredient}
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
    $last = $pdo->query('SELECT pantry_json FROM {menu_week} ORDER BY week_start DESC LIMIT 1')->fetchColumn();
    $defaultPantry = is_string($last) ? (json_decode($last, true) ?: []) : [];
}
$defaultPantry = array_map('intval', $defaultPantry);

$recipeCount = (int)$pdo->query('SELECT COUNT(*) FROM {recipe} WHERE is_active = 1')->fetchColumn();
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

<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Weekmenu">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
</head>
<body>

<header class="topbar">
    <div class="wrap topbar-inner">
        <h1>Weekmenu</h1>
        <nav class="topnav">
            <?php if ($mayEdit): ?>
                <a href="admin.php">Recepten beheren</a>
                <a href="logout.php">Uitloggen</a>
            <?php else: ?>
                <a href="login.php">Inloggen</a>
            <?php endif; ?>
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

    <?php if ($isLocked): ?>
        <div class="alert alert-locked">
            <span>
                <strong>Deze week staat op slot.</strong>
                De lijst is op <?= esc(date('j M, H:i', strtotime((string)$week['locked_at']))) ?>
                naar Bring gestuurd, dus het menu ligt vast. Afstrepen kan gewoon.
            </span>
            <?php if ($mayEdit): ?>
                <button class="btn btn-ghost" data-unlock>Ontgrendelen</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($week === null): ?>

        <section class="empty">
            <p class="empty-text">Voor deze week staat nog geen menu klaar.</p>
            <?php if ($mayEditMenu): ?>
                <button class="btn btn-primary btn-big" data-open-pantry>Genereer weekmenu</button>
            <?php else: ?>
                <p class="hint"><a href="login.php">Log in</a> om een menu te maken.</p>
            <?php endif; ?>
            <?php if ($mayEdit && $recipeCount < 10): ?>
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
                $isLeftover = $hasRecipe && !empty($entry['is_leftover']);
                ?>
                <?php $dayServings = (int)($entry['servings'] ?? 3); ?>
                <article class="day <?= $isJunk ? 'day-junk' : '' ?> <?= $isLeftover ? 'day-leftover' : '' ?>"
                         data-day="<?= $i ?>" data-day-servings="<?= $dayServings ?>">
                    <div class="day-head">
                        <span class="day-name"><?= esc($dayName) ?></span>
                        <span class="day-date"><?= esc(dayDate($current, $i)) ?></span>
                    </div>

                    <div class="day-body">
                        <?php if ($isJunk): ?>
                            <span class="junk-label"><?= esc(JUNK_LABEL) ?></span>
                        <?php elseif ($isLeftover): ?>
                            <span class="leftover-label">
                                Restjes<?php if (isset($leftoverSourceDay[$i])): ?>
                                    van <?= esc(DAY_NAMES[$leftoverSourceDay[$i]]) ?>
                                <?php endif; ?>
                            </span>
                        <?php elseif ($hasRecipe): ?>
                            <div class="recipe">
                                <button class="recipe-name" data-field="name"
                                        data-recipe="<?= (int)$entry['id'] ?>"
                                        title="Bekijk de bereiding"><?= esc($entry['name']) ?></button>
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
                        <div class="day-actions">
                            <?php if ($mayEditMenu): ?>
                                <?php if ($isLeftover): ?>
                                    <button class="btn btn-leftover is-active" data-leftover-revert="<?= $i ?>"
                                            title="Zet deze dag weer leeg">
                                        Restjes<?php if (isset($leftoverSourceDay[$i])): ?>
                                            van <?= esc(DAY_NAMES[$leftoverSourceDay[$i]]) ?>
                                        <?php endif; ?>
                                    </button>
                                <?php else: ?>
                                    <div class="servings servings-day" data-servings-control="<?= $i ?>">
                                        <button class="servings-btn" data-servings="-1" aria-label="Minder personen">&minus;</button>
                                        <span class="servings-value"><span data-servings-value><?= $dayServings ?></span>p</span>
                                        <button class="servings-btn" data-servings="1" aria-label="Meer personen">+</button>
                                    </div>
                                    <?php if (isset($leftoverSuggestion[$i])): ?>
                                        <button class="btn btn-ghost btn-leftover" data-leftover-assign="<?= $i ?>"
                                                data-leftover-source="<?= $leftoverSuggestion[$i]['source_day'] ?>"
                                                title="Dit gerecht was genoeg voor twee dagen">
                                            Restjes van <?= esc(DAY_NAMES[$leftoverSuggestion[$i]['source_day']]) ?>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-reroll" data-reroll="<?= $i ?>" title="Ander gerecht voor deze dag">
                                        Ander gerecht
                                    </button>
                                <?php endif; ?>
                            <?php elseif (!$isLeftover): ?>
                                <span class="chip chip-soft"><?= $dayServings ?> pers.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($mayEditMenu): ?>
            <div class="actions">
                <button class="btn btn-primary" data-open-pantry>Genereer opnieuw</button>
            </div>
        <?php endif; ?>

        <?php if ($shopping !== []): ?>
            <section class="shopping">
                <div class="shopping-head">
                    <div>
                        <h2>Boodschappenlijst</h2>
                        <p class="hint">
                            Opgeteld over de hele week, met het aantal personen dat je per dag
                            hebt ingesteld. Wat je al in huis zei te hebben staat er
                            doorgestreept bij; zet het aan als het toch op is.
                            Naar Bring gaat alleen wat niet is afgestreept.
                        </p>
                    </div>
                    <?php if ($mayEdit): ?>
                        <a class="btn btn-bring" href="bring-export.php?week=<?= esc($current) ?>"
                           title="Zet de lijst in de Bring! app; de week gaat daarna op slot">
                            <?= $isLocked ? 'Opnieuw naar Bring!' : 'Naar Bring!' ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="shopping-groups">
                    <?php foreach ($shopping as $group => $items): ?>
                        <div class="shopping-group">
                            <h3><?= esc(PANTRY_GROUP_LABELS[$group] ?? ucfirst($group)) ?></h3>
                            <ul>
                                <?php foreach ($items as $item): ?>
                                    <li>
                                        <label class="<?= $item['checked'] ? 'is-done' : '' ?>">
                                            <input type="checkbox"
                                                   data-check="<?= esc($item['name']) ?>"
                                                   <?= $item['checked'] ? 'checked' : '' ?>
                                                   <?= $mayEdit ? '' : 'disabled' ?>>
                                            <span>
                                                <span class="shop-amount"
                                                      data-name="<?= esc($item['name']) ?>"
                                                      data-amount="<?= $item['amount'] !== null ? esc((string)round($item['amount'], 4)) : '' ?>"
                                                      data-unit="<?= esc((string)$item['unit']) ?>"></span><?= esc($item['name']) ?>
                                            </span>
                                        </label>
                                    </li>
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
<?php if ($mayEditMenu): ?>
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
<?php endif; ?>

<!-- Receptvenster ------------------------------------------------- -->
<div class="modal" id="recipeModal" hidden>
    <div class="modal-backdrop" data-close-recipe></div>

    <div class="modal-card modal-card-recipe" role="dialog" aria-modal="true" aria-labelledby="recipeTitle">
        <header class="modal-head">
            <h2 id="recipeTitle">&nbsp;</h2>
            <button class="modal-x" data-close-recipe aria-label="Sluiten">&times;</button>
        </header>

        <div class="modal-body">
            <div class="recipe-meta" id="recipeMeta"></div>
            <p class="recipe-note" id="recipeNotes"></p>

            <div class="detail-servings">
                <h3 class="detail-head">Nodig</h3>
                <div class="servings<?= $mayEditMenu ? '' : ' servings-static' ?>" id="recipeServings">
                    <?php if ($mayEditMenu): ?>
                        <button class="servings-btn" data-servings="-1" aria-label="Minder personen">&minus;</button>
                    <?php endif; ?>
                    <span class="servings-value"><span data-servings-value>3</span> pers.</span>
                    <?php if ($mayEditMenu): ?>
                        <button class="servings-btn" data-servings="1" aria-label="Meer personen">+</button>
                    <?php endif; ?>
                </div>
            </div>
            <ul class="detail-ingredients" id="recipeIngredients"></ul>

            <h3 class="detail-head">Bereiding</h3>
            <ol class="detail-steps" id="recipeSteps"></ol>

            <p id="recipeLinkWrap" hidden>
                <a id="recipeLink" href="#" target="_blank" rel="noopener">Volledig recept &rarr;</a>
            </p>
        </div>

        <footer class="modal-foot">
            <span class="hint" id="recipeHint"></span>
            <div class="modal-foot-right">
                <button class="btn btn-ghost" data-close-recipe>Sluiten</button>
            </div>
        </footer>
    </div>
</div>

<script>
    window.WEEKMENU = {
        csrf: <?= json_encode(csrfToken()) ?>,
        weekStart: <?= json_encode($current) ?>,
        weekId: <?= json_encode($week['id'] ?? null) ?>,
        mayEdit: <?= json_encode($mayEdit) ?>,
        mayEditMenu: <?= json_encode($mayEditMenu) ?>
    };
</script>
<script src="assets/app.js"></script>
<script>
    // Alleen op https (of localhost); daarbuiten weigert de browser hem toch.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () {
                // Geen service worker betekent alleen: niet offline te gebruiken.
                // De app werkt verder gewoon.
            });
        });
    }
</script>
</body>
</html>
