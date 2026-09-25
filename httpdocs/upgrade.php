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

    /* --- 1. ontbrekende kolommen --- */
    $hasColumn = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );

    $columns = [
        ['{recipe}',            'recipe',            'steps',    'ALTER TABLE {recipe} ADD COLUMN steps TEXT NULL AFTER notes'],
        ['{recipe}',            'recipe',            'servings', 'ALTER TABLE {recipe} ADD COLUMN servings TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER weekend_only'],
        ['{recipe}',            'recipe',            'makes_leftovers', 'ALTER TABLE {recipe} ADD COLUMN makes_leftovers TINYINT(1) NOT NULL DEFAULT 0 AFTER weekend_only'],
        ['{recipe_ingredient}', 'recipe_ingredient', 'amount',   'ALTER TABLE {recipe_ingredient} ADD COLUMN amount DECIMAL(8,2) NULL'],
        ['{recipe_ingredient}', 'recipe_ingredient', 'unit',     'ALTER TABLE {recipe_ingredient} ADD COLUMN unit VARCHAR(20) NULL'],
        ['{menu_entry}',        'menu_entry',        'servings',  'ALTER TABLE {menu_entry} ADD COLUMN servings TINYINT UNSIGNED NOT NULL DEFAULT 3'],
        ['{menu_entry}',        'menu_entry',        'is_leftover', 'ALTER TABLE {menu_entry} ADD COLUMN is_leftover TINYINT(1) NOT NULL DEFAULT 0 AFTER is_junkfood'],
        ['{menu_week}',         'menu_week',         'locked_at', 'ALTER TABLE {menu_week} ADD COLUMN locked_at DATETIME NULL'],
        ['{recipe}',            'recipe',            'preference', 'ALTER TABLE {recipe} ADD COLUMN preference TINYINT NOT NULL DEFAULT 0 AFTER makes_leftovers'],
        ['{recipe}',            'recipe',            'season',   'ALTER TABLE {recipe} ADD COLUMN season VARCHAR(40) NULL AFTER preference'],
        ['{menu_entry}',        'menu_entry',        'thaw',     'ALTER TABLE {menu_entry} ADD COLUMN thaw TINYINT(1) NOT NULL DEFAULT 0 AFTER is_leftover'],
    ];

    $addedColumns = [];
    foreach ($columns as [$table, $bare, $column, $sql]) {
        $hasColumn->execute([DB_PREFIX . $bare, $column]);
        if ((int)$hasColumn->fetchColumn() === 0) {
            $pdo->exec($sql);
            $addedColumns[] = $column;
        }
    }

    $log[] = $addedColumns === []
        ? 'Alle kolommen stonden al klaar.'
        : 'Kolommen toegevoegd: <code>' . implode('</code>, <code>', $addedColumns) . '</code>.';

    // Seizoenen alleen de eerste keer invullen, als de kolom net bestaat.
    // Daarna zijn ze van jou; een volgende upgrade laat ze met rust. Op
    // naam, dus ook als je een meegeleverd recept als eigen hebt gemarkeerd.
    if (in_array('season', $addedColumns, true)) {
        $setSeason = $pdo->prepare('UPDATE {recipe} SET season = ? WHERE name = ?');
        $seasoned  = 0;
        foreach (seedSeasons() as $name => $season) {
            $setSeason->execute([$season, $name]);
            $seasoned += $setSeason->rowCount();
        }
        $log[] = "Seizoen ingevuld bij <strong>$seasoned</strong> recepten (stamppot in de winter, enz.). "
               . 'Aanpassen kan per recept onder Recepten beheren.';
    }

    /* --- 1b. tabel voor instellingen --- */
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {setting} (
            name  VARCHAR(40)  NOT NULL PRIMARY KEY,
            value VARCHAR(255) NOT NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        "INSERT INTO {setting} (name, value) VALUES ('default_servings', '3')
         ON DUPLICATE KEY UPDATE value = value"
    );
    $pdo->exec(
        "INSERT INTO {setting} (name, value) VALUES ('planning_days', '7')
         ON DUPLICATE KEY UPDATE value = value"
    );
    $log[] = 'Instellingen staan klaar, standaard 3 personen en 7 dagen.';

    /* --- 1c. afvinklijst van de boodschappen --- */
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {shopping_check} (
            week_id INT UNSIGNED NOT NULL,
            item    VARCHAR(80)  NOT NULL,
            PRIMARY KEY (week_id, item)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $log[] = 'Afvinklijst staat klaar.';

    /* --- 1d. aanbiedingen: levende cache en bevroren momentopname --- */
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {deal} (
            ingredient_id      INT UNSIGNED NOT NULL,
            retailer           VARCHAR(20)  NOT NULL,
            product_name       VARCHAR(160) NOT NULL,
            price              DECIMAL(6,2) NOT NULL,
            original_price     DECIMAL(6,2) NULL,
            savings_percentage DECIMAL(5,2) NULL,
            valid_until        DATE NULL,
            checked_at         DATETIME NOT NULL,
            PRIMARY KEY (ingredient_id, retailer)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {week_deal} (
            week_id            INT UNSIGNED NOT NULL,
            ingredient_id      INT UNSIGNED NOT NULL,
            retailer           VARCHAR(20)  NOT NULL,
            product_name       VARCHAR(160) NOT NULL,
            price              DECIMAL(6,2) NOT NULL,
            original_price     DECIMAL(6,2) NULL,
            savings_percentage DECIMAL(5,2) NULL,
            valid_until        DATE NULL,
            PRIMARY KEY (week_id, ingredient_id, retailer)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $hasColumn->execute([DB_PREFIX . 'deal', 'base_product_id']);
    if ((int)$hasColumn->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE {deal} ADD COLUMN base_product_id VARCHAR(64) NULL AFTER product_name');
    }
    $hasColumn->execute([DB_PREFIX . 'deal', 'brand']);
    if ((int)$hasColumn->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE {deal} ADD COLUMN brand VARCHAR(60) NULL AFTER base_product_id');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {deal_exclusion} (
            ingredient_id INT UNSIGNED NOT NULL,
            retailer      VARCHAR(20)  NOT NULL,
            product_key   VARCHAR(160) NOT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ingredient_id, retailer, product_key)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Geleerde woorden zijn vervangen door de strikte match in
    // dealIsIngredientMatch(). De cache is nog met de oude filter gevuld:
    // leeggooien en de verversingstijd wissen, zodat het eerstvolgende
    // genereren opnieuw ophaalt. Bevroren weken (week_deal) blijven staan.
    $pdo->exec('DROP TABLE IF EXISTS {deal_exclusion_word}');
    $pdo->exec('DELETE FROM {deal}');
    $pdo->exec("DELETE FROM {setting} WHERE name = 'deals_checked_at'");
    $log[] = 'Kortingscache geleegd, wordt bij het volgende weekmenu opnieuw opgehaald.';

    $log[] = 'Aanbiedingen-tabellen staan klaar.';

    /* --- 1e. gebruikers, inlogpogingen en pushmeldingen --- */
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {app_user} (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(60)  NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role          VARCHAR(10)  NOT NULL DEFAULT \'reader\',
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_username (username)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {login_attempt} (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip           VARCHAR(45) NOT NULL,
            attempted_at DATETIME    NOT NULL,
            KEY idx_ip (ip, attempted_at)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {push_subscription} (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            endpoint   VARCHAR(500) CHARACTER SET ascii NOT NULL,
            p256dh     VARCHAR(120) NOT NULL,
            auth       VARCHAR(40)  NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_endpoint (endpoint),
            CONSTRAINT `' . DB_PREFIX . 'fk_push_user` FOREIGN KEY (user_id)
                REFERENCES `' . DB_PREFIX . 'app_user`(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Eerste beheerder uit het oude, losse adminwachtwoord. Daarna doet
    // ADMIN_PASSWORD in config.local.php niets meer.
    if ((int)$pdo->query('SELECT COUNT(*) FROM {app_user}')->fetchColumn() === 0) {
        if (!defined('ADMIN_PASSWORD') || ADMIN_PASSWORD === 'VERANDER_MIJ_OOK') {
            throw new RuntimeException('Geen ADMIN_PASSWORD in inc/config.local.php om de eerste beheerder mee aan te maken.');
        }
        $pdo->prepare("INSERT INTO {app_user} (username, password_hash, role) VALUES ('admin', ?, 'admin')")
            ->execute([password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT)]);
        $log[] = 'Eerste beheerder aangemaakt: gebruikersnaam <strong>admin</strong>, met het wachtwoord '
               . 'waarmee je tot nu toe inlogde. Maak onder Gebruikers je eigen accounts aan.';
    }
    $log[] = 'Gebruikers en meldingen staan klaar.';

    /* --- 1f. geleerde koppelingen bij het importeren van recepten --- */
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS {ingredient_alias} (
            alias         VARCHAR(80)  NOT NULL PRIMARY KEY,
            ingredient_id INT UNSIGNED NULL,
            CONSTRAINT `' . DB_PREFIX . 'fk_alias_ingredient` FOREIGN KEY (ingredient_id)
                REFERENCES `' . DB_PREFIX . 'ingredient`(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $log[] = 'Tabel voor het importeren van recepten staat klaar.';

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
        'INSERT INTO {recipe} (name, category, effort, weekend_only, servings, notes, steps, url, is_mine)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $updRec  = $pdo->prepare(
        'UPDATE {recipe}
            SET category = ?, effort = ?, weekend_only = ?, servings = ?, notes = ?, steps = ?
          WHERE id = ? AND is_mine = 0'
    );
    $delLink = $pdo->prepare('DELETE FROM {recipe_ingredient} WHERE recipe_id = ?');
    $insLink = $pdo->prepare(
        'INSERT IGNORE INTO {recipe_ingredient} (recipe_id, ingredient_id, is_key, amount, unit)
         VALUES (?, ?, 1, ?, ?)'
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
                $r['category'], $r['effort'], $r['weekend_only'], $r['servings'] ?? 4,
                $r['notes'] ?? null, $r['steps'] ?? null, $recipeId,
            ]);
            $updated++;
        } else {
            $insRec->execute([
                $r['name'], $r['category'], $r['effort'], $r['weekend_only'],
                $r['servings'] ?? 4, $r['notes'] ?? null, $r['steps'] ?? null,
                $r['url'] ?? null,
            ]);
            $recipeId = (int)$pdo->lastInsertId();
            $inserted++;
        }

        $delLink->execute([$recipeId]);
        foreach ($r['ingredients'] as [$ingName, $amount, $unit]) {
            if (!isset($ingIds[$ingName])) {
                $insIng->execute([$ingName, 'rest', 0]);
                $ingIds[$ingName] = (int)$pdo->lastInsertId();
            }
            $insLink->execute([$recipeId, $ingIds[$ingName], $amount, $unit]);
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
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
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
