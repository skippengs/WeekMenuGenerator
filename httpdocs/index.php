<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/deals.php';
require __DIR__ . '/inc/generator.php';
require __DIR__ . '/inc/push.php';

startSession();
$pdo = db();

// Kijken mag iedereen, ook zonder in te loggen. Veranderen en afvinken
// vanaf bewerker; een lezer logt in voor zijn eigen meldingen.
$user    = currentUser();
$mayEdit = hasRole('editor');

$current  = weekStart($_GET['week'] ?? null);
$week     = loadWeek($pdo, $current);
$shopping = $week ? shoppingList($pdo, $week['id']) : [];

// Restjes: van welke dag is een restjesdag afkomstig, en op welke lege
// dag mag je "restjes van ..." aanklikken.
$leftoverSourceDay  = [];
$leftoverSuggestion = [];
if ($week !== null) {
    // Recepten die al een restjesdag hebben krijgen geen tweede knop.
    $hasLeftoverDay = [];
    foreach ($week['days'] as $d) {
        if (!empty($d['is_leftover']) && $d['id'] !== null) {
            $hasLeftoverDay[$d['id']] = true;
        }
    }

    foreach ($week['days'] as $di => $d) {
        if (!empty($d['is_leftover']) && $d['id'] !== null) {
            foreach ($week['days'] as $sdi => $sd) {
                if ($sdi < $di && $sd['id'] === $d['id'] && empty($sd['is_leftover'])) {
                    $leftoverSourceDay[$di] = $sdi;
                    break;
                }
            }
        }

        if ($d['id'] !== null && empty($d['is_leftover']) && !empty($d['makes_leftovers'])
            && !isset($hasLeftoverDay[$d['id']])) {
            $target = leftoverTargetDay($di);
            if ($target !== null && empty($week['days'][$target]['is_leftover'])) {
                $leftoverSuggestion[$target] = ['source_day' => $di, 'name' => $d['name']];
            }
        }
    }
}

// Een week die naar Bring is gestuurd staat op slot: het menu ligt vast,
// maar afstrepen tijdens het boodschappen doen moet gewoon kunnen.
$isLocked    = $week !== null && $week['locked_at'] !== null;
$mayEditMenu = $mayEdit && !$isLocked;

// Een dag zonder gerecht buiten de geplande dagen (of verhuisd) is "vrij":
// alleen bewerkers zien er een smalle kaart van, om iets heen te wisselen
// of er restjes op te zetten.
$planned = planningDays($pdo);
$isFreeDay = static fn(int $i): bool => $week !== null && $i !== JUNK_DAY_INDEX
    && (!isset($week['days'][$i]) || ($week['days'][$i]['id'] === null && $i >= $planned));

// Het ⋯-menu op een kaart. Nu alleen wisselen; ruimte voor meer.
$dayMenu = static function (int $i) use (&$swapOptions): string {
    if (!isset($swapOptions[$i])) {
        return '';
    }
    return '<details class="day-menu">'
        . '<summary class="day-menu-btn" title="Meer" aria-label="Meer"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></summary>'
        . '<div class="day-menu-list">'
        . '<button type="button" data-swap-open="' . $i . '" data-swap-options="' . esc(json_encode($swapOptions[$i])) . '">'
        . '<i class="fa-solid fa-right-left" aria-hidden="true"></i> Wisselen met…</button>'
        . '</div></details>';
};

// Per dag: met welke andere dagen kun je wisselen, en waarom niet.
$swapOptions = [];
if ($mayEditMenu) {
    foreach (DAY_NAMES as $i => $unused) {
        foreach (DAY_NAMES as $j => $name) {
            if ($i === $j || $i === JUNK_DAY_INDEX || $j === JUNK_DAY_INDEX) {
                continue;
            }
            $e = $week['days'][$j] ?? null;
            $swapOptions[$i][] = [
                'day'    => $j,
                'name'   => $name,
                'what'   => $isFreeDay($j) ? 'Vrij'
                    : ($e['id'] === null ? 'Geen recept'
                    : (!empty($e['is_leftover']) ? 'Restjes' : $e['name'])),
                'reason' => swapProblem($week['days'], $i, $j),
            ];
        }
    }
}

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

// Wanneer stond elk gerecht van deze week vorige keer op tafel, gerekend
// vanaf de dag zelf. Restjesdagen tellen niet als "gegeten".
$lastEaten = [];
if ($week !== null) {
    $stmt = $pdo->prepare(
        'SELECT me.day_index,
                (SELECT MAX(DATE_ADD(mw2.week_start, INTERVAL me2.day_index DAY))
                   FROM {menu_entry} me2
                   JOIN {menu_week} mw2 ON mw2.id = me2.week_id
                  WHERE me2.recipe_id = me.recipe_id AND me2.is_leftover = 0
                    AND DATE_ADD(mw2.week_start, INTERVAL me2.day_index DAY)
                      < DATE_ADD(mw.week_start, INTERVAL me.day_index DAY)) AS prev
           FROM {menu_entry} me
           JOIN {menu_week} mw ON mw.id = me.week_id
          WHERE me.week_id = ? AND me.recipe_id IS NOT NULL'
    );
    $stmt->execute([$week['id']]);
    foreach ($stmt as $row) {
        $dayIndex = (int)$row['day_index'];
        $lastEaten[$dayIndex] = lastEatenText($row['prev'], false, dayDateIso($current, $dayIndex));
    }
}

// Vandaag, als je naar de huidige week kijkt.
$todayIndex = $current === weekStart() ? (int)date('N') - 1 : null;

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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
      integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
      crossorigin="anonymous" referrerpolicy="no-referrer">
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
            <?php if ($week !== null): ?>
                <button class="icon-btn" type="button" data-share title="Delen" aria-label="Delen">
                    <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
                </button>
                <button class="icon-btn" type="button" data-print title="Afdrukken" aria-label="Afdrukken">
                    <i class="fa-solid fa-print" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
            <?php if ($user !== null): ?>
                <button class="icon-btn" type="button" data-push hidden title="Meldingen" aria-label="Meldingen">
                    <i class="fa-solid fa-bell-slash" aria-hidden="true"></i>
                </button>
                <?php if ($mayEdit): ?>
                    <a class="icon-btn" href="admin.php" title="Recepten beheren" aria-label="Recepten beheren">
                        <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
                <a class="icon-btn" href="logout.php" title="Uitloggen (<?= esc($user['username']) ?>)"
                   aria-label="Uitloggen">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                </a>
            <?php else: ?>
                <a class="icon-btn" href="login.php" title="Inloggen" aria-label="Inloggen">
                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                </a>
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
            <?php elseif ($user === null): ?>
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
                <?php if ($isFreeDay($i)): ?>
                    <?php if (!$mayEditMenu) { continue; } ?>
                    <article class="day day-free" data-day="<?= $i ?>">
                        <div class="day-head">
                            <span class="day-name"><?= esc($dayName) ?></span>
                            <span class="day-date"><?= esc(dayDate($current, $i)) ?></span>
                        </div>
                        <div class="day-body"><span class="free-label">Vrij</span></div>
                        <div class="day-actions">
                            <?php if (isset($leftoverSuggestion[$i])): ?>
                                <button class="btn btn-ghost btn-leftover" data-leftover-assign="<?= $i ?>"
                                        data-leftover-source="<?= $leftoverSuggestion[$i]['source_day'] ?>"
                                        title="Dit gerecht was genoeg voor twee dagen">
                                    Restjes van <?= esc(DAY_NAMES[$leftoverSuggestion[$i]['source_day']]) ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($i !== JUNK_DAY_INDEX): ?>
                                <button class="btn btn-reroll" data-reroll="<?= $i ?>" title="Kies een gerecht voor deze dag">
                                    Kies gerecht
                                </button>
                            <?php endif; ?>
                            <?= $dayMenu($i) ?>
                        </div>
                    </article>
                    <?php continue; ?>
                <?php endif; ?>
                <?php if (!array_key_exists($i, $week['days'])) { continue; } ?>
                <?php
                $entry      = $week['days'][$i];
                $isJunk     = $i === JUNK_DAY_INDEX;
                $hasRecipe  = $entry['id'] !== null;
                $isLeftover = $hasRecipe && !empty($entry['is_leftover']);
                ?>
                <?php $dayServings = (int)($entry['servings'] ?? 3); ?>
                <article class="day <?= $isJunk ? 'day-junk' : '' ?> <?= $isLeftover ? 'day-leftover' : '' ?> <?= $i === $todayIndex ? 'day-today' : '' ?>"
                         data-day="<?= $i ?>" data-day-servings="<?= $dayServings ?>"
                         data-thaw="<?= !empty($entry['thaw']) ? 1 : 0 ?>">
                    <div class="day-head">
                        <span class="day-name"><?= esc($dayName) ?></span>
                        <span class="day-date">
                            <?php if ($i === $todayIndex): ?><span class="chip chip-now">vandaag</span><?php endif; ?>
                            <?= esc(dayDate($current, $i)) ?>
                        </span>
                        <?php if ($hasRecipe && !$isLeftover): ?>
                            <span class="day-thaw" title="Uit de vriezer halen" aria-label="Uit de vriezer halen"
                                  <?= empty($entry['thaw']) ? 'hidden' : '' ?>>
                                <i class="fa-solid fa-snowflake" aria-hidden="true"></i>
                            </span>
                        <?php endif; ?>
                        <?php if ($hasRecipe && !$isLeftover && isset($lastEaten[$i])): ?>
                            <button class="day-history" type="button" data-history="<?= esc($lastEaten[$i]) ?>"
                                    title="Wanneer vorige keer?" aria-label="Wanneer vorige keer?">
                                <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
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
                                <?= $dayMenu($i) ?>
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
                                    <?php
                                    // Beste aanbieding staat vooraan (shoppingList() sorteert op
                                    // korting); erna staat hoeveel andere winkels hem ook hebben.
                                    $deals = $item['deals'];
                                    $best  = $deals[0] ?? null;
                                    $extra = count($deals) - 1;
                                    ?>
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
                                            <?php if ($best !== null): ?>
                                                <button type="button" class="deal-badge"
                                                        data-deal-name="<?= esc($item['name']) ?>"
                                                        data-ingredient-id="<?= (int)$item['ingredient_id'] ?>"
                                                        data-deals="<?= esc(json_encode($deals, JSON_UNESCAPED_UNICODE)) ?>">
                                                    <?= esc($best['label']) ?><?= $extra > 0 ? ' +' . $extra : '' ?>
                                                </button>
                                            <?php endif; ?>
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
                <?php if ($mayEdit): ?>
                    <button class="btn btn-ghost btn-thaw" type="button" data-thaw-toggle hidden
                            title="Stuurt de avond ervoor een melding">
                        <i class="fa-solid fa-snowflake" aria-hidden="true"></i>
                        <span data-thaw-label>Ligt in de vriezer</span>
                    </button>
                <?php endif; ?>
                <button class="btn btn-ghost" data-close-recipe>Sluiten</button>
            </div>
        </footer>
    </div>
</div>

<!-- Kortingsvenster ------------------------------------------------- -->
<div class="modal" id="dealModal" hidden>
    <div class="modal-backdrop" data-close-deal></div>

    <div class="modal-card modal-card-deal" role="dialog" aria-modal="true" aria-labelledby="dealTitle">
        <header class="modal-head">
            <h2 id="dealTitle">&nbsp;</h2>
            <button class="modal-x" data-close-deal aria-label="Sluiten">&times;</button>
        </header>

        <div class="modal-body">
            <ul class="deal-list" id="dealList"></ul>
        </div>

        <footer class="modal-foot">
            <div class="modal-foot-right">
                <button class="btn btn-ghost" data-close-deal>Sluiten</button>
            </div>
        </footer>
    </div>
</div>

<!-- Wisselvenster -------------------------------------------------- -->
<div class="modal" id="swapModal" hidden>
    <div class="modal-backdrop" data-close-swap></div>

    <div class="modal-card modal-card-deal" role="dialog" aria-modal="true" aria-labelledby="swapTitle">
        <header class="modal-head">
            <h2 id="swapTitle">&nbsp;</h2>
            <button class="modal-x" data-close-swap aria-label="Sluiten">&times;</button>
        </header>

        <div class="modal-body">
            <ul class="swap-list" id="swapList"></ul>
        </div>

        <footer class="modal-foot">
            <div class="modal-foot-right">
                <button class="btn btn-ghost" data-close-swap>Annuleren</button>
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
        mayEditMenu: <?= json_encode($mayEditMenu) ?>,
        vapidPublic: <?= json_encode($user !== null ? vapidKeys($pdo)['public'] : null) ?>
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
