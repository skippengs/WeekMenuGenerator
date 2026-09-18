<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Bijwerken van een bestaande installatie.
 *
 * Voegt de kolom voor de bereiding toe, vult de bereiding bij de
 * meegeleverde recepten en zet nieuwe recepten erbij.
 *
 * Eigen recepten blijven ongemoeid. Meegeleverde recepten die uit de
 * lijst zijn gehaald worden op non-actief gezet, niet verwijderd, zodat
 * je weekgeschiedenis heel blijft.
 *
 * Verwijder dit bestand als je klaar bent.
 */

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/seed_data.php';

$log    = [];
$errors = [];

try {
    $pdo = db();

    /* --- 1. kolom voor de bereiding --- */
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([DB_PREFIX . 'recipe', 'steps']);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE {recipe} ADD COLUMN steps TEXT NULL AFTER notes');
        $log[] = 'Kolom <code>steps</code> toegevoegd aan de receptentabel.';
    } else {
        $log[] = 'Kolom <code>steps</code> bestond al.';
    }

    /* --- 2. bestaande recepten ophalen --- */
    $existing = [];
    foreach ($pdo->query('SELECT id, name, is_mine FROM {recipe}') as $row) {
        $existing[$row['name']] = ['id' => (int)$row['id'], 'is_mine' => (int)$row['is_mine']];
    }

    $ingIds = [];
    foreach ($pdo->query('SELECT id, name FROM {ingredient}') as $row) {
        $ingIds[$row['name']] = (int)$row['id'];
    }

    $insIng  = $pdo->prepare('INSERT INTO {ingredient} (name, category, is_pantry_item) VALUES (?, ?, ?)');
    $insRec  = $pdo->prepare(
        'INSERT INTO {recipe} (name, category, effort, weekend_only, notes, steps, url, is_mine)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $updRec  = $pdo->prepare(
        'UPDATE {recipe}
            SET category = ?, effort = ?, weekend_only = ?, notes = ?, steps = ?
          WHERE id = ? AND is_mine = 0'
    );
    $delLink = $pdo->prepare('DELETE FROM {recipe_ingredient} WHERE recipe_id = ?');
    $insLink = $pdo->prepare(
        'INSERT IGNORE INTO {recipe_ingredient} (recipe_id, ingredient_id, is_key) VALUES (?, ?, 1)'
    );

    /* --- 3. ontbrekende voorraaditems --- */
    $added = 0;
    foreach (seedIngredients() as [$name, $cat, $pantry]) {
        if (!isset($ingIds[$name])) {
            $insIng->execute([$name, $cat, $pantry]);
            $ingIds[$name] = (int)$pdo->lastInsertId();
            $added++;
        }
    }
    if ($added > 0) {
        $log[] = "Nieuwe ingredienten toegevoegd: <strong>$added</strong>.";
    }

    /* --- 4. recepten bijwerken en toevoegen --- */
    $seedNames = [];
    $updated   = 0;
    $inserted  = 0;
    $skipped   = 0;

    $pdo->beginTransaction();

    foreach (seedRecipes() as $r) {
        $seedNames[$r['name']] = true;

        if (isset($existing[$r['name']])) {
            if ($existing[$r['name']]['is_mine'] === 1) {
                // Jij hebt een recept met dezelfde naam. Daar blijven we af.
                $skipped++;
                continue;
            }
            $recipeId = $existing[$r['name']]['id'];
            $updRec->execute([
                $r['category'], $r['effort'], $r['weekend_only'],
                $r['notes'] ?? null, $r['steps'] ?? null, $recipeId,
            ]);
            $updated++;
        } else {
            $insRec->execute([
                $r['name'], $r['category'], $r['effort'], $r['weekend_only'],
                $r['notes'] ?? null, $r['steps'] ?? null, $r['url'] ?? null,
            ]);
            $recipeId = (int)$pdo->lastInsertId();
            $inserted++;
        }

        $delLink->execute([$recipeId]);
        foreach ($r['ingredients'] as $ingName) {
            if (!isset($ingIds[$ingName])) {
                $insIng->execute([$ingName, 'rest', 0]);
                $ingIds[$ingName] = (int)$pdo->lastInsertId();
            }
            $insLink->execute([$recipeId, $ingIds[$ingName]]);
        }
    }

    /* --- 5. vervallen meegeleverde recepten op non-actief --- */
    $retired = [];
    foreach ($existing as $name => $info) {
        if ($info['is_mine'] === 0 && !isset($seedNames[$name])) {
            $retired[] = $name;
        }
    }

    if ($retired !== []) {
        $in = implode(',', array_fill(0, count($retired), '?'));
        $pdo->prepare("UPDATE {recipe} SET is_active = 0 WHERE name IN ($in) AND is_mine = 0")
            ->execute($retired);
    }

    $pdo->commit();

    $log[] = "Recepten bijgewerkt: <strong>$updated</strong>, nieuw toegevoegd: <strong>$inserted</strong>.";
    if ($skipped > 0) {
        $log[] = "Overgeslagen omdat je er zelf een met die naam hebt: <strong>$skipped</strong>.";
    }
    if ($retired !== []) {
        $log[] = 'Op non-actief gezet (niet verwijderd, dus je geschiedenis blijft heel): <strong>'
               . esc(implode(', ', $retired)) . '</strong>. '
               . 'Wil je ze terug, zet ze dan weer aan via Recepten beheren.';
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weekmenu bijwerken</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="install">
<main class="wrap">
    <h1>Weekmenu bijwerken</h1>

    <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= esc($e) ?></div>
    <?php endforeach; ?>

    <ul class="loglist">
        <?php foreach ($log as $line): ?>
            <li><?= $line ?></li>
        <?php endforeach; ?>
    </ul>

    <?php if (!$errors): ?>
        <div class="alert alert-ok"><strong>Klaar.</strong></div>
        <div class="alert alert-error">
            <strong>Verwijder <code>upgrade.php</code> nu van de server.</strong>
        </div>
        <p><a class="btn btn-primary" href="index.php">Naar het weekmenu</a></p>
    <?php endif; ?>
</main>
</body>
</html>
