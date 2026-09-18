<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

startSession();
requireAdmin();

$pdo    = db();
$notice = null;
$error  = null;

/* ------------------------------------------------------------------
 * Ingredienten uit een tekstveld naar de koppeltabel
 * ------------------------------------------------------------------ */
function syncIngredients(PDO $pdo, int $recipeId, string $raw): void
{
    $names = [];
    foreach (preg_split('/[,\n]/', $raw) ?: [] as $part) {
        $name = mb_strtolower(trim($part));
        if ($name !== '' && mb_strlen($name) <= 80) {
            $names[$name] = true;
        }
    }

    $pdo->prepare('DELETE FROM {recipe_ingredient} WHERE recipe_id = ?')->execute([$recipeId]);

    if ($names === []) {
        return;
    }

    $find   = $pdo->prepare('SELECT id FROM {ingredient} WHERE name = ?');
    $create = $pdo->prepare('INSERT INTO {ingredient} (name, category, is_pantry_item) VALUES (?, ?, 0)');
    $link   = $pdo->prepare(
        'INSERT IGNORE INTO {recipe_ingredient} (recipe_id, ingredient_id, is_key) VALUES (?, ?, 1)'
    );

    foreach (array_keys($names) as $name) {
        $find->execute([$name]);
        $id = $find->fetchColumn();

        if ($id === false) {
            $create->execute([$name, 'rest']);
            $id = $pdo->lastInsertId();
        }

        $link->execute([$recipeId, (int)$id]);
    }
}

/* ------------------------------------------------------------------
 * Formulieren afhandelen
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Sessie verlopen. Probeer het opnieuw.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id          = (int)($_POST['id'] ?? 0);
                $name        = trim((string)($_POST['name'] ?? ''));
                $category    = (string)($_POST['category'] ?? 'overig');
                $effort      = (int)($_POST['effort'] ?? 2);
                $weekendOnly = isset($_POST['weekend_only']) ? 1 : 0;
                $isMine      = isset($_POST['is_mine']) ? 1 : 0;
                $notes       = trim((string)($_POST['notes'] ?? ''));
                $url         = trim((string)($_POST['url'] ?? ''));

                if ($name === '') {
                    throw new RuntimeException('Geef het gerecht een naam.');
                }
                if (!isset(CATEGORIES[$category])) {
                    $category = 'overig';
                }
                if ($effort < 1 || $effort > 3) {
                    $effort = 2;
                }

                if ($id > 0) {
                    $pdo->prepare(
                        'UPDATE {recipe}
                            SET name = ?, category = ?, effort = ?, weekend_only = ?,
                                notes = ?, url = ?, is_mine = ?
                          WHERE id = ?'
                    )->execute([$name, $category, $effort, $weekendOnly,
                                $notes ?: null, $url ?: null, $isMine, $id]);
                    $notice = 'Recept bijgewerkt.';
                } else {
                    $pdo->prepare(
                        'INSERT INTO {recipe} (name, category, effort, weekend_only, notes, url, is_mine)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$name, $category, $effort, $weekendOnly,
                                $notes ?: null, $url ?: null, $isMine]);
                    $id = (int)$pdo->lastInsertId();
                    $notice = 'Recept toegevoegd.';
                }

                syncIngredients($pdo, $id, (string)($_POST['ingredients'] ?? ''));

            } elseif ($action === 'delete') {
                $pdo->prepare('DELETE FROM {recipe} WHERE id = ?')->execute([(int)$_POST['id']]);
                $notice = 'Recept verwijderd.';

            } elseif ($action === 'toggle_active') {
                $pdo->prepare('UPDATE {recipe} SET is_active = 1 - is_active WHERE id = ?')
                    ->execute([(int)$_POST['id']]);
                $notice = 'Aan- of uitgezet.';

            } elseif ($action === 'save_pantry') {
                $checked = array_map('intval', (array)($_POST['pantry'] ?? []));
                $pdo->exec('UPDATE {ingredient} SET is_pantry_item = 0');

                if ($checked !== []) {
                    $in = implode(',', array_fill(0, count($checked), '?'));
                    $pdo->prepare("UPDATE {ingredient} SET is_pantry_item = 1 WHERE id IN ($in)")
                        ->execute($checked);
                }
                $notice = 'Voorraadlijst opgeslagen.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

/* ------------------------------------------------------------------
 * Gegevens voor de pagina
 * ------------------------------------------------------------------ */
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM {recipe} WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;

    if ($editing) {
        $stmt = $pdo->prepare(
            'SELECT i.name FROM {recipe_ingredient} ri
               JOIN {ingredient} i ON i.id = ri.ingredient_id
              WHERE ri.recipe_id = ?
              ORDER BY i.name'
        );
        $stmt->execute([(int)$editing['id']]);
        $editing['ingredients'] = implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

$recipes = $pdo->query(
    'SELECT r.*, COUNT(ri.ingredient_id) AS ing_count
       FROM {recipe} r
  LEFT JOIN {recipe_ingredient} ri ON ri.recipe_id = r.id
      GROUP BY r.id
      ORDER BY r.is_mine DESC, r.name'
)->fetchAll();

$ingredients = $pdo->query('SELECT id, name, category, is_pantry_item FROM {ingredient} ORDER BY category, name')->fetchAll();

$val = static fn(string $key, $fallback = '') => $editing[$key] ?? $fallback;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recepten beheren</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<header class="topbar">
    <div class="wrap topbar-inner">
        <h1>Recepten beheren</h1>
        <nav class="topnav">
            <a href="index.php">Weekmenu</a> &nbsp;
            <a href="logout.php">Uitloggen</a>
        </nav>
    </div>
</header>

<main class="wrap">

    <?php if ($notice): ?><div class="alert alert-ok"><?= esc($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>

    <div class="admin-grid">

        <!-- formulier ------------------------------------------------ -->
        <form class="card" method="post" action="admin.php">
            <h2><?= $editing ? 'Recept bewerken' : 'Nieuw recept' ?></h2>

            <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$val('id', 0) ?>">

            <div class="field">
                <label for="f-name">Gerecht</label>
                <input type="text" id="f-name" name="name" required maxlength="160"
                       value="<?= esc((string)$val('name')) ?>" placeholder="Bijv. Macaroni met gehakt">
            </div>

            <div class="field">
                <label for="f-category">Soort</label>
                <select id="f-category" name="category">
                    <?php foreach (CATEGORIES as $key => $label): ?>
                        <option value="<?= esc($key) ?>" <?= $val('category', 'overig') === $key ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Van elke soort komt er hoogstens één per week op tafel.</p>
            </div>

            <div class="field">
                <label for="f-effort">Hoeveel werk</label>
                <select id="f-effort" name="effort">
                    <?php foreach (EFFORTS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= (int)$val('effort', 2) === $key ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Uitgebreide gerechten komen vooral in het weekend langs.</p>
            </div>

            <div class="field">
                <label for="f-ingredients">Ingrediënten</label>
                <textarea id="f-ingredients" name="ingredients"
                          placeholder="gehakt, macaroni, ui, kaas"><?= esc((string)$val('ingredients')) ?></textarea>
                <p class="field-hint">
                    Komma's ertussen. Alleen de kenmerkende ingrediënten &mdash;
                    die bepalen of dit gerecht omhoog schuift als je ze in huis hebt.
                </p>
            </div>

            <div class="field">
                <label for="f-notes">Notitie</label>
                <textarea id="f-notes" name="notes" placeholder="Optioneel"><?= esc((string)$val('notes')) ?></textarea>
            </div>

            <div class="field">
                <label for="f-url">Link naar recept</label>
                <input type="url" id="f-url" name="url" maxlength="400"
                       value="<?= esc((string)$val('url')) ?>" placeholder="https://">
            </div>

            <div class="field field-check">
                <input type="checkbox" id="f-weekend" name="weekend_only" value="1"
                       <?= (int)$val('weekend_only', 0) === 1 ? 'checked' : '' ?>>
                <label for="f-weekend">Alleen in het weekend</label>
            </div>

            <div class="field field-check">
                <input type="checkbox" id="f-mine" name="is_mine" value="1"
                       <?= (int)$val('is_mine', $editing ? 0 : 1) === 1 ? 'checked' : '' ?>>
                <label for="f-mine">Eigen recept</label>
            </div>

            <button class="btn btn-primary" type="submit">
                <?= $editing ? 'Opslaan' : 'Toevoegen' ?>
            </button>
            <?php if ($editing): ?>
                <a class="btn btn-ghost" href="admin.php">Annuleren</a>
            <?php endif; ?>
        </form>

        <!-- lijst ---------------------------------------------------- -->
        <div class="card">
            <h2><?= count($recipes) ?> recepten</h2>

            <table class="recipe-table">
                <thead>
                    <tr>
                        <th>Gerecht</th>
                        <th class="col-hide">Soort</th>
                        <th class="col-hide">Werk</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recipes as $r): ?>
                    <tr class="<?= (int)$r['is_active'] === 0 ? 'is-inactive' : '' ?>">
                        <td>
                            <?php if ((int)$r['is_mine'] === 1): ?>
                                <span class="mine-dot" title="Eigen recept"></span>
                            <?php endif; ?>
                            <?= esc($r['name']) ?>
                            <?php if ((int)$r['weekend_only'] === 1): ?>
                                <span class="chip chip-soft">weekend</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-hide"><?= esc(CATEGORIES[$r['category']] ?? $r['category']) ?></td>
                        <td class="col-hide"><?= (int)$r['effort'] ?></td>
                        <td class="col-actions">
                            <a class="linkbtn" href="admin.php?edit=<?= (int)$r['id'] ?>">bewerk</a>

                            <form method="post" action="admin.php" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="linkbtn" type="submit">
                                    <?= (int)$r['is_active'] === 1 ? 'pauzeer' : 'activeer' ?>
                                </button>
                            </form>

                            <form method="post" action="admin.php" style="display:inline"
                                  onsubmit="return confirm('<?= esc($r['name']) ?> verwijderen?')">
                                <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="linkbtn linkbtn-danger" type="submit">wis</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- voorraadlijst ------------------------------------------------ -->
    <form class="card" method="post" action="admin.php" style="margin-bottom:40px">
        <h2>Voorraadlijst</h2>
        <p class="hint" style="margin-top:-10px">
            Deze items verschijnen in het venster dat opent als je een weekmenu genereert.
            Houd het kort: alleen dingen waarvan je echt weet of ze in huis zijn.
        </p>

        <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_pantry">

        <div class="pantry-items" style="margin-bottom:18px">
            <?php foreach ($ingredients as $ing): ?>
                <label class="pantry-item">
                    <input type="checkbox" name="pantry[]" value="<?= (int)$ing['id'] ?>"
                           <?= (int)$ing['is_pantry_item'] === 1 ? 'checked' : '' ?>>
                    <span><?= esc($ing['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <button class="btn btn-primary" type="submit">Voorraadlijst opslaan</button>
    </form>

</main>
</body>
</html>
