<?php
declare(strict_types=1);
define('WEEKMENU', true);

/*
 * Installatie in de browser.
 *
 * Vraagt om de databasegegevens, test of ze werken, schrijft ze weg naar
 * inc/config.local.php en zet daarna de tabellen en de startrecepten klaar.
 *
 * Verwijder dit bestand zodra je klaar bent.
 */

require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/seed_data.php';

const CONFIG_FILE = __DIR__ . '/inc/config.local.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$errors = [];
$log    = [];
$done   = false;

/* ------------------------------------------------------------------
 * Staat er al een configuratie, en werkt die?
 * ------------------------------------------------------------------ */
$alreadyInstalled = false;

if (is_file(CONFIG_FILE)) {
    require CONFIG_FILE;

    if (defined('DB_NAME')) {
        try {
            $test = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $existingPrefix = defined('DB_PREFIX') ? DB_PREFIX : '';
            $test->query('SELECT 1 FROM `' . $existingPrefix . 'recipe` LIMIT 1');
            $alreadyInstalled = true;
        } catch (Throwable $e) {
            // Config bestaat maar werkt niet - laat het formulier zien.
        }
    }
}

/* ------------------------------------------------------------------
 * Formulier verwerken
 * ------------------------------------------------------------------ */
$form = [
    'db_host'   => defined('DB_HOST')   ? DB_HOST   : 'localhost',
    'db_name'   => defined('DB_NAME')   ? DB_NAME   : '',
    'db_user'   => defined('DB_USER')   ? DB_USER   : '',
    'db_prefix' => defined('DB_PREFIX') ? DB_PREFIX : '',
];

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'De pagina stond te lang open. Probeer het opnieuw.';
    }

    $form['db_host']   = trim((string)($_POST['db_host'] ?? 'localhost'));
    $form['db_name']   = trim((string)($_POST['db_name'] ?? ''));
    $form['db_user']   = trim((string)($_POST['db_user'] ?? ''));
    $form['db_prefix'] = trim((string)($_POST['db_prefix'] ?? ''));
    $dbPass            = (string)($_POST['db_pass'] ?? '');
    $adminPass         = (string)($_POST['admin_pass'] ?? '');

    if ($form['db_host'] === '') { $errors[] = 'Vul de databaseserver in (meestal localhost).'; }
    if ($form['db_name'] === '') { $errors[] = 'Vul de naam van de database in.'; }
    if ($form['db_user'] === '') { $errors[] = 'Vul de databasegebruiker in.'; }

    // Het voorvoegsel komt rechtstreeks in de tabelnamen terecht, dus
    // hier alleen letters, cijfers en liggende streepjes toestaan.
    if (!preg_match('/^[A-Za-z0-9_]*$/', $form['db_prefix'])) {
        $errors[] = 'Het voorvoegsel mag alleen letters, cijfers en _ bevatten.';
    } elseif (strlen($form['db_prefix']) > 24) {
        $errors[] = 'Houd het voorvoegsel korter dan 24 tekens.';
    }

    if (mb_strlen($adminPass) < 8) {
        $errors[] = 'Kies een adminwachtwoord van minstens 8 tekens.';
    }

    /* --- verbinding testen --- */
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $form['db_host'], $form['db_name']),
                $form['db_user'],
                $dbPass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $log[] = 'Verbinding met de database werkt.';
        } catch (PDOException $e) {
            $errors[] = 'Geen verbinding: ' . $e->getMessage();
        }
    }

    /* --- config wegschrijven --- */
    if (!$errors && $pdo !== null) {
        $contents = "<?php\n"
            . "/*\n"
            . " * Aangemaakt door install.php op " . date('d-m-Y H:i') . ".\n"
            . " * Deze gegevens horen hier te blijven staan; de map inc/ is\n"
            . " * afgeschermd met een eigen .htaccess.\n"
            . " */\n"
            . "if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }\n\n"
            . 'define(' . var_export('DB_HOST', true) . ', ' . var_export($form['db_host'], true) . ");\n"
            . 'define(' . var_export('DB_NAME', true) . ', ' . var_export($form['db_name'], true) . ");\n"
            . 'define(' . var_export('DB_USER', true) . ', ' . var_export($form['db_user'], true) . ");\n"
            . 'define(' . var_export('DB_PASS', true) . ', ' . var_export($dbPass, true) . ");\n"
            . 'define(' . var_export('DB_PREFIX', true) . ', ' . var_export($form['db_prefix'], true) . ");\n\n"
            . 'define(' . var_export('ADMIN_PASSWORD', true) . ', ' . var_export($adminPass, true) . ");\n";

        if (@file_put_contents(CONFIG_FILE, $contents) === false) {
            $errors[] = 'Kon inc/config.local.php niet schrijven. '
                      . 'Geef de map inc/ schrijfrechten, of maak het bestand zelf aan '
                      . 'met de inhoud die hieronder staat.';
            $manual = $contents;
        } else {
            @chmod(CONFIG_FILE, 0640);
            $log[] = 'Gegevens opgeslagen in inc/config.local.php.';
        }
    }

    /* --- tabellen --- */
    if (!$errors && $pdo !== null) {
        // Voorvoegsel voor zowel de tabelnamen als de namen van de
        // foreign keys. Die laatste moeten uniek zijn binnen de hele
        // database, dus zonder voorvoegsel botsen twee installaties.
        $p = $form['db_prefix'];

        $schema = [
            $p . 'recipe' => "
                CREATE TABLE IF NOT EXISTS `{$p}recipe` (
                    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name         VARCHAR(160)  NOT NULL,
                    category     VARCHAR(40)   NOT NULL DEFAULT 'overig',
                    effort       TINYINT UNSIGNED NOT NULL DEFAULT 2,
                    weekend_only TINYINT(1)    NOT NULL DEFAULT 0,
                    notes        TEXT          NULL,
                    steps        TEXT          NULL,
                    url          VARCHAR(400)  NULL,
                    is_mine      TINYINT(1)    NOT NULL DEFAULT 0,
                    is_active    TINYINT(1)    NOT NULL DEFAULT 1,
                    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            $p . 'ingredient' => "
                CREATE TABLE IF NOT EXISTS `{$p}ingredient` (
                    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name           VARCHAR(80) NOT NULL,
                    category       VARCHAR(40) NOT NULL DEFAULT 'rest',
                    is_pantry_item TINYINT(1)  NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_name (name),
                    KEY idx_pantry (is_pantry_item)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            $p . 'recipe_ingredient' => "
                CREATE TABLE IF NOT EXISTS `{$p}recipe_ingredient` (
                    recipe_id     INT UNSIGNED NOT NULL,
                    ingredient_id INT UNSIGNED NOT NULL,
                    is_key        TINYINT(1)   NOT NULL DEFAULT 1,
                    PRIMARY KEY (recipe_id, ingredient_id),
                    KEY idx_ingredient (ingredient_id),
                    CONSTRAINT `{$p}fk_ri_recipe`     FOREIGN KEY (recipe_id)     REFERENCES `{$p}recipe`(id)     ON DELETE CASCADE,
                    CONSTRAINT `{$p}fk_ri_ingredient` FOREIGN KEY (ingredient_id) REFERENCES `{$p}ingredient`(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            $p . 'menu_week' => "
                CREATE TABLE IF NOT EXISTS `{$p}menu_week` (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    week_start  DATE     NOT NULL,
                    pantry_json TEXT     NULL,
                    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_week (week_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            $p . 'menu_entry' => "
                CREATE TABLE IF NOT EXISTS `{$p}menu_entry` (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    week_id     INT UNSIGNED NOT NULL,
                    day_index   TINYINT UNSIGNED NOT NULL,
                    recipe_id   INT UNSIGNED NULL,
                    is_junkfood TINYINT(1)   NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_week_day (week_id, day_index),
                    KEY idx_recipe (recipe_id),
                    CONSTRAINT `{$p}fk_me_week`   FOREIGN KEY (week_id)   REFERENCES `{$p}menu_week`(id) ON DELETE CASCADE,
                    CONSTRAINT `{$p}fk_me_recipe` FOREIGN KEY (recipe_id) REFERENCES `{$p}recipe`(id)    ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            foreach ($schema as $sql) {
                $pdo->exec($sql);
            }
            $log[] = 'Tabellen aangemaakt: ' . implode(', ', array_keys($schema)) . '.';
        } catch (Throwable $e) {
            $errors[] = 'Tabellen aanmaken mislukt: ' . $e->getMessage();
        }
    }

    /* --- startrecepten --- */
    if (!$errors && $pdo !== null) {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$p}recipe`")->fetchColumn();

            if ($count > 0) {
                $log[] = "Er stonden al $count recepten in de database. "
                       . 'Die zijn met rust gelaten.';
            } else {
                $pdo->beginTransaction();

                $insIng = $pdo->prepare(
                    "INSERT INTO `{$p}ingredient` (name, category, is_pantry_item) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE category = VALUES(category), is_pantry_item = VALUES(is_pantry_item)"
                );
                foreach (seedIngredients() as [$name, $cat, $pantry]) {
                    $insIng->execute([$name, $cat, $pantry]);
                }

                $ingIds = [];
                foreach ($pdo->query("SELECT id, name FROM `{$p}ingredient`") as $row) {
                    $ingIds[$row['name']] = (int)$row['id'];
                }

                $insRec = $pdo->prepare(
                    "INSERT INTO `{$p}recipe` (name, category, effort, weekend_only, notes, steps, url, is_mine)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0)"
                );
                $insLink = $pdo->prepare(
                    "INSERT IGNORE INTO `{$p}recipe_ingredient` (recipe_id, ingredient_id, is_key) VALUES (?, ?, 1)"
                );
                $insMissing = $pdo->prepare(
                    "INSERT INTO `{$p}ingredient` (name, category, is_pantry_item) VALUES (?, ?, 0)"
                );

                $recipes = seedRecipes();
                foreach ($recipes as $r) {
                    $insRec->execute([
                        $r['name'], $r['category'], $r['effort'], $r['weekend_only'],
                        $r['notes'] ?? null, $r['steps'] ?? null, $r['url'] ?? null,
                    ]);
                    $recipeId = (int)$pdo->lastInsertId();

                    foreach ($r['ingredients'] as $ingName) {
                        if (!isset($ingIds[$ingName])) {
                            $insMissing->execute([$ingName, 'rest']);
                            $ingIds[$ingName] = (int)$pdo->lastInsertId();
                        }
                        $insLink->execute([$recipeId, $ingIds[$ingName]]);
                    }
                }

                $pdo->commit();
                $log[] = 'Toegevoegd: ' . count($recipes) . ' recepten en '
                       . count($ingIds) . ' ingredienten.';
            }

            $done = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Vullen mislukt: ' . $e->getMessage();
        }
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weekmenu installeren</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="install">
<main class="wrap">

    <h1>Weekmenu installeren</h1>

    <?php if ($alreadyInstalled): ?>

        <div class="alert alert-warn">
            <strong>Dit is al geinstalleerd.</strong>
            De database staat klaar en de gegevens zijn bekend.
        </div>
        <div class="alert alert-error">
            <strong>Verwijder <code>install.php</code> nu van de server.</strong>
            Zolang dit bestand bestaat, kan iedereen die het adres kent hem openen.
        </div>
        <p><a class="btn btn-primary" href="index.php">Naar het weekmenu</a></p>

    <?php elseif ($done): ?>

        <ul class="loglist">
            <?php foreach (array_filter($log) as $line): ?>
                <li><?= esc($line) ?></li>
            <?php endforeach; ?>
        </ul>

        <div class="alert alert-ok"><strong>Klaar.</strong> Alles staat klaar om te gebruiken.</div>
        <div class="alert alert-error">
            <strong>Verwijder nu <code>install.php</code> van de server.</strong>
        </div>
        <p><a class="btn btn-primary" href="index.php">Naar het weekmenu</a></p>

    <?php else: ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= esc($e) ?></div>
        <?php endforeach; ?>

        <?php if (!empty($manual)): ?>
            <p class="hint">Maak <code>inc/config.local.php</code> aan met deze inhoud:</p>
            <pre class="codeblock"><?= esc($manual) ?></pre>
        <?php endif; ?>

        <?php if (!$errors): ?>
            <p class="hint">
                Deze gegevens vind je in Plesk onder <strong>Databases</strong>.
                Ze worden opgeslagen in <code>inc/config.local.php</code> en gaan
                verder nergens heen.
            </p>
        <?php endif; ?>

        <form class="card" method="post" action="install.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">

            <div class="field">
                <label for="db_host">Databaseserver</label>
                <input type="text" id="db_host" name="db_host" required
                       value="<?= esc($form['db_host']) ?>">
                <p class="field-hint">Bij mijndomein is dit vrijwel altijd <code>localhost</code>.</p>
            </div>

            <div class="field">
                <label for="db_name">Naam van de database</label>
                <input type="text" id="db_name" name="db_name" required
                       value="<?= esc($form['db_name']) ?>">
            </div>

            <div class="field">
                <label for="db_user">Databasegebruiker</label>
                <input type="text" id="db_user" name="db_user" required
                       value="<?= esc($form['db_user']) ?>">
            </div>

            <div class="field">
                <label for="db_pass">Wachtwoord van de database</label>
                <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
            </div>

            <div class="field">
                <label for="db_prefix">Voorvoegsel voor de tabellen</label>
                <input type="text" id="db_prefix" name="db_prefix" maxlength="24"
                       pattern="[A-Za-z0-9_]*" placeholder="weekmenu_"
                       value="<?= esc($form['db_prefix']) ?>">
                <p class="field-hint">
                    Leeg laten mag: de tabellen heten dan gewoon <code>recipe</code>,
                    <code>ingredient</code> enzovoort. Deel je deze database met een
                    andere site, vul dan bijvoorbeeld <code>weekmenu_</code> in &mdash;
                    de tabellen heten dan <code>weekmenu_recipe</code>. Vergeet het
                    liggende streepje aan het eind niet.
                </p>
            </div>

            <hr style="border:0;border-top:1px solid var(--border);margin:22px 0">

            <div class="field">
                <label for="admin_pass">Adminwachtwoord (kies zelf)</label>
                <input type="password" id="admin_pass" name="admin_pass" required
                       minlength="8" autocomplete="new-password">
                <p class="field-hint">
                    Hiermee log je straks in op <code>/admin.php</code> om recepten
                    toe te voegen. Minstens 8 tekens.
                </p>
            </div>

            <button class="btn btn-primary" type="submit">Testen en installeren</button>
        </form>

    <?php endif; ?>

</main>
</body>
</html>
