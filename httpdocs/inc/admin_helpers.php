<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/*
 * Gedeeld tussen admin.php (eerste render) en api/admin_*.php (ververst
 * na een wijziging, zonder de pagina opnieuw te laden): het inlezen van
 * ingrediëntregels en de html-snippets voor de receptentabel en de
 * voorraadlijst.
 */

const INGREDIENT_UNITS = ['g', 'gram', 'kg', 'ml', 'l', 'liter', 'el', 'tl', 'teen', 'tenen',
               'blik', 'blikje', 'pak', 'pakje', 'pot', 'bosje', 'snuf', 'plak', 'plakken'];

/**
 * Leest een regel als "400 g gehakt", "2 teen knoflook" of gewoon "ui".
 * Geeft [naam, hoeveelheid, eenheid] terug, of null bij een lege regel.
 */
function parseIngredientLine(string $line): ?array
{
    $line   = trim($line);
    $amount = null;
    $unit   = null;

    if ($line === '') {
        return null;
    }

    // Begint de regel met een getal? Dan is dat de hoeveelheid.
    if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\\s+(.*)$/u', $line, $m)) {
        $amount = (float)str_replace(',', '.', $m[1]);
        $line   = trim($m[2]);

        // Staat daar een eenheid achter, dan hoort die er ook bij.
        $parts = preg_split('/\\s+/u', $line, 2);
        if ($parts !== false && count($parts) === 2 && in_array(mb_strtolower($parts[0]), INGREDIENT_UNITS, true)) {
            $unit = mb_strtolower($parts[0]);
            $line = trim($parts[1]);

            // Schrijfwijzen gelijktrekken.
            $same = ['gram' => 'g', 'liter' => 'l', 'tenen' => 'teen',
                     'blikje' => 'blik', 'pakje' => 'pak', 'plakken' => 'plak'];
            $unit = $same[$unit] ?? $unit;
        }
    }

    $name = mb_strtolower(trim($line));
    if ($name === '' || mb_strlen($name) > 80) {
        return null;
    }

    return [$name, $amount, $unit];
}

function syncIngredients(PDO $pdo, int $recipeId, string $raw): void
{
    // Regel voor regel, niet op komma's: die zitten in "0,5 l melk".
    $rows = [];
    foreach (preg_split('/\\r\\n|\\r|\\n/', $raw) ?: [] as $line) {
        $parsed = parseIngredientLine($line);
        if ($parsed !== null) {
            $rows[$parsed[0]] = $parsed;   // zelfde naam twee keer: laatste wint
        }
    }

    $pdo->prepare('DELETE FROM {recipe_ingredient} WHERE recipe_id = ?')->execute([$recipeId]);

    if ($rows === []) {
        return;
    }

    $find   = $pdo->prepare('SELECT id FROM {ingredient} WHERE name = ?');
    $create = $pdo->prepare('INSERT INTO {ingredient} (name, category, is_pantry_item) VALUES (?, ?, 0)');
    $link   = $pdo->prepare(
        'INSERT IGNORE INTO {recipe_ingredient} (recipe_id, ingredient_id, is_key, amount, unit)
         VALUES (?, ?, 1, ?, ?)'
    );

    foreach ($rows as [$name, $amount, $unit]) {
        $find->execute([$name]);
        $id = $find->fetchColumn();

        if ($id === false) {
            $create->execute([$name, 'rest']);
            $id = $pdo->lastInsertId();
        }

        $link->execute([$recipeId, (int)$id, $amount, $unit]);
    }
}

/** Ingrediënten van een recept terug als tekst, in de vorm waarin je ze intypt. */
function ingredientsAsText(PDO $pdo, int $recipeId): string
{
    $stmt = $pdo->prepare(
        'SELECT i.name, ri.amount, ri.unit FROM {recipe_ingredient} ri
           JOIN {ingredient} i ON i.id = ri.ingredient_id
          WHERE ri.recipe_id = ?
          ORDER BY i.name'
    );
    $stmt->execute([$recipeId]);

    $lines = [];
    foreach ($stmt as $row) {
        $amount = '';
        if ($row['amount'] !== null) {
            $amount = rtrim(rtrim(number_format((float)$row['amount'], 2, ',', ''), '0'), ',');
        }
        $lines[] = trim($amount . ' ' . (string)$row['unit'] . ' ' . $row['name']);
    }
    return implode(PHP_EOL, $lines);
}

function fetchRecipesForAdmin(PDO $pdo): array
{
    return $pdo->query(
        'SELECT r.*, COUNT(ri.ingredient_id) AS ing_count
           FROM {recipe} r
      LEFT JOIN {recipe_ingredient} ri ON ri.recipe_id = r.id
          GROUP BY r.id
          ORDER BY r.is_mine DESC, r.name'
    )->fetchAll();
}

function renderRecipeRow(array $r): string
{
    ob_start();
    ?>
    <tr class="<?= (int)$r['is_active'] === 0 ? 'is-inactive' : '' ?>" data-id="<?= (int)$r['id'] ?>">
        <td>
            <?php if ((int)$r['is_mine'] === 1): ?>
                <span class="mine-dot" title="Eigen recept"></span>
            <?php endif; ?>
            <?= esc($r['name']) ?>
            <?php if ((int)$r['weekend_only'] === 1): ?>
                <span class="chip chip-soft">weekend</span>
            <?php endif; ?>
            <?php if ((int)$r['makes_leftovers'] === 1): ?>
                <span class="chip chip-soft">restjes</span>
            <?php endif; ?>
        </td>
        <td class="col-hide"><?= esc(CATEGORIES[$r['category']] ?? $r['category']) ?></td>
        <td class="col-hide"><?= (int)$r['effort'] ?></td>
        <td class="col-actions">
            <button class="linkbtn" type="button" data-edit="<?= (int)$r['id'] ?>">bewerk</button>
            <button class="linkbtn" type="button" data-toggle="<?= (int)$r['id'] ?>">
                <?= (int)$r['is_active'] === 1 ? 'pauzeer' : 'activeer' ?>
            </button>
            <button class="linkbtn linkbtn-danger" type="button"
                    data-delete="<?= (int)$r['id'] ?>" data-name="<?= esc($r['name']) ?>">wis</button>
        </td>
    </tr>
    <?php
    return (string)ob_get_clean();
}

function renderRecipeTableBody(PDO $pdo): string
{
    $html = '';
    foreach (fetchRecipesForAdmin($pdo) as $r) {
        $html .= renderRecipeRow($r);
    }
    return $html;
}

function fetchIngredientsForAdmin(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, category, is_pantry_item FROM {ingredient} ORDER BY category, name')->fetchAll();
}

function renderPantryItems(array $ingredients): string
{
    ob_start();
    foreach ($ingredients as $ing) {
        ?>
        <label class="pantry-item">
            <input type="checkbox" name="pantry[]" value="<?= (int)$ing['id'] ?>"
                   <?= (int)$ing['is_pantry_item'] === 1 ? 'checked' : '' ?>>
            <span><?= esc($ing['name']) ?></span>
        </label>
        <?php
    }
    return (string)ob_get_clean();
}

/** De twee keuzelijsten van het samenvoeg-formulier. */
function renderIngredientOptions(array $ingredients): string
{
    ob_start();
    foreach ($ingredients as $ing) {
        ?>
        <option value="<?= (int)$ing['id'] ?>"><?= esc($ing['name']) ?></option>
        <?php
    }
    return (string)ob_get_clean();
}

/*
 * Kortingen beheren: de losse producten die "klopt
 * niet, uitsluiten" in het kortingsvenster wegschrijft (zie inc/deals.php)
 * waren tot nu toe alleen met de hand in de database terug te draaien. Dit
 * geeft er een tabblad in admin.php voor, met dezelfde tabel/knop-opzet als
 * de receptentabel hierboven.
 */

/** Winkelnaam bij een retailer-code, of de code zelf als hij niet bekend is. */
function dealRetailerLabel(string $retailer): string
{
    return DEALS_RETAILERS[$retailer] ?? $retailer;
}

/** Leesbare versie van een product_key ("name:iets" / "id:123") voor in de tabel. */
function dealProductKeyLabel(string $key): string
{
    if (str_starts_with($key, 'name:')) {
        return substr($key, 5);
    }
    if (str_starts_with($key, 'id:')) {
        return 'productcode ' . substr($key, 3);
    }
    return $key;
}

function fetchDealExclusionsForAdmin(PDO $pdo): array
{
    return $pdo->query(
        'SELECT de.ingredient_id, de.retailer, de.product_key, de.created_at, i.name AS ingredient_name
           FROM {deal_exclusion} de
           JOIN {ingredient} i ON i.id = de.ingredient_id
          ORDER BY de.created_at DESC'
    )->fetchAll();
}

function renderDealExclusionRow(array $e): string
{
    ob_start();
    ?>
    <tr data-ingredient-id="<?= (int)$e['ingredient_id'] ?>" data-retailer="<?= esc($e['retailer']) ?>"
        data-product-key="<?= esc($e['product_key']) ?>">
        <td><?= esc($e['ingredient_name']) ?></td>
        <td class="col-hide"><?= esc(dealRetailerLabel($e['retailer'])) ?></td>
        <td class="col-hide"><?= esc(dealProductKeyLabel($e['product_key'])) ?></td>
        <td class="col-actions">
            <button class="linkbtn linkbtn-danger" type="button"
                    data-delete-deal-exclusion="1"
                    data-ingredient-id="<?= (int)$e['ingredient_id'] ?>"
                    data-retailer="<?= esc($e['retailer']) ?>"
                    data-product-key="<?= esc($e['product_key']) ?>">wis</button>
        </td>
    </tr>
    <?php
    return (string)ob_get_clean();
}

function renderDealExclusionTable(PDO $pdo): string
{
    $html = '';
    foreach (fetchDealExclusionsForAdmin($pdo) as $e) {
        $html .= renderDealExclusionRow($e);
    }
    return $html;
}
