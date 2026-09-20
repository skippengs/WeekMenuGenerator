<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/admin_helpers.php';

startSession();
requireAdmin();

$pdo = db();

$recipes     = fetchRecipesForAdmin($pdo);
$ingredients = fetchIngredientsForAdmin($pdo);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recepten beheren</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
      integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
      crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
</head>
<body>

<header class="topbar">
    <div class="wrap topbar-inner">
        <h1>Recepten beheren</h1>
        <nav class="topnav">
            <a class="icon-btn" href="index.php" title="Naar het weekmenu" aria-label="Naar het weekmenu">
                <i class="fa-solid fa-house" aria-hidden="true"></i>
            </a>
            <a class="icon-btn" href="logout.php" title="Uitloggen" aria-label="Uitloggen">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            </a>
        </nav>
    </div>
</header>

<main class="wrap">

    <div class="tabs" role="tablist">
        <button class="tab-btn is-active" type="button" role="tab" data-tab="recepten">Recepten</button>
        <button class="tab-btn" type="button" role="tab" data-tab="instellingen">Instellingen</button>
        <button class="tab-btn" type="button" role="tab" data-tab="voorraad">Voorraadlijst</button>
    </div>

    <!-- recepten ------------------------------------------------------ -->
    <section class="tab-panel" id="tab-recepten" data-tab-panel="recepten">
        <div class="card">
            <div class="card-head">
                <h2 id="recipeCount"><?= count($recipes) ?> recepten</h2>
                <button class="btn btn-primary" type="button" data-new-recipe>
                    <i class="fa-solid fa-plus" aria-hidden="true"></i> Nieuw recept
                </button>
            </div>

            <table class="recipe-table">
                <thead>
                    <tr>
                        <th>Gerecht</th>
                        <th class="col-hide">Soort</th>
                        <th class="col-hide">Werk</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody id="recipeTableBody">
                    <?php foreach ($recipes as $r): ?>
                        <?= renderRecipeRow($r) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- instellingen ---------------------------------------------------- -->
    <section class="tab-panel" id="tab-instellingen" data-tab-panel="instellingen" hidden>
        <form class="card" id="settingsForm">
            <h2>Instellingen</h2>

            <div class="field">
                <label for="f-default-servings">Standaard aantal personen</label>
                <input type="number" id="f-default-servings" name="default_servings"
                       min="1" max="20" value="<?= (int)defaultServings($pdo) ?>">
                <p class="field-hint">
                    Hiermee begint elke dag in een nieuw weekmenu. Eet er een keer
                    iemand mee, dan pas je die dag los aan in het weekmenu zelf.
                </p>
            </div>

            <button class="btn btn-primary" type="submit">Opslaan</button>
        </form>
    </section>

    <!-- voorraadlijst ------------------------------------------------ -->
    <section class="tab-panel" id="tab-voorraad" data-tab-panel="voorraad" hidden>
        <form class="card" id="pantryForm">
            <h2>Voorraadlijst</h2>
            <p class="hint" style="margin-top:-10px">
                Deze items verschijnen in het venster dat opent als je een weekmenu genereert.
                Houd het kort: alleen dingen waarvan je echt weet of ze in huis zijn.
            </p>

            <div class="pantry-items" id="pantryItemsList" style="margin-bottom:18px">
                <?= renderPantryItems($ingredients) ?>
            </div>

            <button class="btn btn-primary" type="submit">Voorraadlijst opslaan</button>
        </form>

        <form class="card" id="ingredientMergeForm" style="margin-top:16px">
            <h2>Ingrediënten samenvoegen</h2>
            <p class="hint" style="margin-top:-10px">
                Staat hetzelfde product onder twee namen in de lijst, bijvoorbeeld
                "ui" en "uien"? Recepten die de eerste naam gebruiken, gebruiken
                daarna de tweede; de eerste naam verdwijnt.
            </p>

            <div class="ingredient-merge">
                <div class="field">
                    <label for="f-merge-from">Naam die verdwijnt</label>
                    <select id="f-merge-from" name="from_id" required>
                        <option value="">Kies een ingrediënt...</option>
                        <?= renderIngredientOptions($ingredients) ?>
                    </select>
                </div>
                <i class="fa-solid fa-arrow-right ingredient-merge-arrow" aria-hidden="true"></i>
                <div class="field">
                    <label for="f-merge-into">Wordt samengevoegd met</label>
                    <select id="f-merge-into" name="into_id" required>
                        <option value="">Kies een ingrediënt...</option>
                        <?= renderIngredientOptions($ingredients) ?>
                    </select>
                </div>
            </div>

            <button class="btn btn-primary" type="submit">Samenvoegen</button>
        </form>
    </section>

</main>

<!-- Receptvenster (nieuw / bewerken) ------------------------------- -->
<div class="modal" id="recipeEditModal" hidden>
    <div class="modal-backdrop" data-close-recipe-edit></div>

    <div class="modal-card modal-card-editor" role="dialog" aria-modal="true" aria-labelledby="recipeEditTitle">
        <form id="recipeEditForm">
            <header class="modal-head">
                <h2 id="recipeEditTitle">Nieuw recept</h2>
                <button class="modal-x" type="button" data-close-recipe-edit aria-label="Sluiten">&times;</button>
            </header>

            <div class="modal-body">
                <input type="hidden" name="id" value="0">

                <div class="field">
                    <label for="f-name">Gerecht</label>
                    <input type="text" id="f-name" name="name" required maxlength="160"
                           placeholder="Bijv. Macaroni met gehakt">
                </div>

                <div class="field">
                    <label for="f-category">Soort</label>
                    <select id="f-category" name="category">
                        <?php foreach (CATEGORIES as $key => $label): ?>
                            <option value="<?= esc($key) ?>"><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">Van elke soort komt er hoogstens één per week op tafel.</p>
                </div>

                <div class="field">
                    <label for="f-effort">Hoeveel werk</label>
                    <select id="f-effort" name="effort">
                        <?php foreach (EFFORTS as $key => $label): ?>
                            <option value="<?= $key ?>"><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">Uitgebreide gerechten komen vooral in het weekend langs.</p>
                </div>

                <div class="field">
                    <label for="f-servings">Voor hoeveel personen</label>
                    <input type="number" id="f-servings" name="servings" min="1" max="20" value="4">
                    <p class="field-hint">
                        Hoort bij de hoeveelheden hieronder. De app rekent zelf om naar
                        het aantal personen dat je in het weekmenu kiest.
                    </p>
                </div>

                <div class="field">
                    <label for="f-ingredients">Ingrediënten</label>
                    <div class="ingredient-input-wrap">
                        <textarea id="f-ingredients" name="ingredients" rows="7" autocomplete="off"
                                  placeholder="400 g gehakt&#10;400 g macaroni&#10;2 ui&#10;100 g kaas"></textarea>
                        <ul class="ingredient-suggest" id="ingredientSuggest" hidden></ul>
                    </div>
                    <p class="field-hint">
                        Eén per regel, hoeveelheid eerst: <code>400 g gehakt</code>,
                        <code>2 teen knoflook</code>, <code>1 blik tomatenblokjes</code>.
                        Zonder hoeveelheid mag ook. Alleen de kenmerkende ingrediënten &mdash;
                        die bepalen of dit gerecht omhoog schuift als je ze in huis hebt.
                    </p>
                </div>

                <div class="field">
                    <label for="f-steps">Bereiding</label>
                    <textarea id="f-steps" name="steps" rows="7"
                              placeholder="Kook de aardappelen gaar.&#10;Bak het gehakt rul.&#10;Alles in een schaal, kaas erover."></textarea>
                    <p class="field-hint">Eén stap per regel. Verschijnt als genummerde lijst als je op het gerecht klikt.</p>
                </div>

                <div class="field">
                    <label for="f-notes">Notitie</label>
                    <textarea id="f-notes" name="notes" placeholder="Optioneel"></textarea>
                    <p class="field-hint">Korte opmerking, staat onder de naam in het weekmenu.</p>
                </div>

                <div class="field">
                    <label for="f-url">Link naar recept</label>
                    <input type="url" id="f-url" name="url" maxlength="400" placeholder="https://">
                </div>

                <div class="field field-check">
                    <input type="checkbox" id="f-weekend" name="weekend_only" value="1">
                    <label for="f-weekend">Alleen in het weekend</label>
                </div>

                <div class="field field-check">
                    <input type="checkbox" id="f-leftovers" name="makes_leftovers" value="1">
                    <label for="f-leftovers">Genoeg voor restjes (2 dagen later)</label>
                    <p class="field-hint">
                        Je kunt dan in het weekmenu zelf een dag twee dagen later
                        aanwijzen als restjesdag. Die telt niet extra mee op de
                        boodschappenlijst.
                    </p>
                </div>

                <div class="field field-check">
                    <input type="checkbox" id="f-mine" name="is_mine" value="1" checked>
                    <label for="f-mine">Eigen recept</label>
                </div>
            </div>

            <footer class="modal-foot">
                <button class="btn btn-ghost" type="button" data-close-recipe-edit>Annuleren</button>
                <button class="btn btn-primary" type="submit" id="recipeEditSubmit">Toevoegen</button>
            </footer>
        </form>
    </div>
</div>

<script>
    window.WEEKMENU = {
        csrf: <?= json_encode(csrfToken()) ?>,
        ingredientNames: <?= json_encode(array_column($ingredients, 'name'), JSON_UNESCAPED_UNICODE) ?>,
        ingredientUnits: <?= json_encode(INGREDIENT_UNITS) ?>
    };
</script>
<script src="assets/app.js"></script>
</body>
</html>
