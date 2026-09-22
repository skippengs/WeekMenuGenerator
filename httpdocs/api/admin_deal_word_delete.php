<?php
declare(strict_types=1);
define('WEEKMENU', true);

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/admin_helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Alleen POST'], 405);
}

requireAdminJson();

$input = jsonIn();

if (!checkCsrf($input['csrf'] ?? null)) {
    jsonOut(['error' => 'Sessie verlopen. Ververs de pagina.'], 419);
}

$word = trim(mb_strtolower((string)($input['word'] ?? ''), 'UTF-8'));
if ($word === '') {
    jsonOut(['error' => 'Onbekend woord'], 422);
}

try {
    $pdo = db();
    $pdo->prepare('DELETE FROM {deal_exclusion_word} WHERE word = ?')->execute([$word]);

    $wordTable = renderDealExclusionWordTable($pdo);
    $wordCount = count(fetchDealExclusionWordsForAdmin($pdo));
} catch (Throwable $e) {
    jsonOut(['error' => 'Verwijderen mislukt'], 500);
}

jsonOut([
    'ok'              => true,
    'notice'          => 'Woord "' . $word . '" verwijderd.',
    'deal_word_table' => $wordTable,
    'deal_word_count' => $wordCount,
]);
