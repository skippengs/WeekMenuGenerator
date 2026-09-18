<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/*
 * config.local.php bevat de echte gegevens: database en adminwachtwoord.
 * install.php schrijft dat bestand voor je, dus je hoeft hieronder niets
 * met de hand aan te passen. Wat daar staat wint van wat hier staat.
 *
 * De rest van dit bestand zijn de standaardwaarden en de knoppen waar je
 * aan kunt draaien.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

/* ---------------------------------------------------------------
 * Database - deze gegevens vind je in Plesk onder "Databases"
 * --------------------------------------------------------------- */
defined('DB_HOST')    or define('DB_HOST',    'localhost');
defined('DB_NAME')    or define('DB_NAME',    'weekmenu');
defined('DB_USER')    or define('DB_USER',    'weekmenu');
defined('DB_PASS')    or define('DB_PASS',    'VERANDER_MIJ');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// Voorvoegsel voor de tabelnamen, bijvoorbeeld 'weekmenu_'. Alleen nodig
// als je de database deelt met een andere site. Leeg laten is prima.
defined('DB_PREFIX') or define('DB_PREFIX', '');

/* ---------------------------------------------------------------
 * Admin paneel - kies hier je eigen wachtwoord
 * --------------------------------------------------------------- */
defined('ADMIN_PASSWORD') or define('ADMIN_PASSWORD', 'VERANDER_MIJ_OOK');

/* ---------------------------------------------------------------
 * Generator instellingen
 * --------------------------------------------------------------- */

// Een recept dat korter dan dit aantal weken geleden op tafel stond,
// wordt overgeslagen. Zet op 2 voor meer herhaling, 5 voor minder.
defined('COOLDOWN_WEEKS') or define('COOLDOWN_WEEKS', 3);

// Hoeveel zwaarder telt een recept per ingredient dat je in huis hebt.
// 0.9 = elk raak ingredient maakt de kans ongeveer twee keer zo groot.
defined('PANTRY_BOOST') or define('PANTRY_BOOST', 0.9);

// Gewicht voor een recept dat nog nooit gekozen is, zodat nieuwe
// recepten snel een keer aan de beurt komen.
defined('NEVER_SERVED_WEIGHT') or define('NEVER_SERVED_WEIGHT', 30.0);

// Plafond op het recency-gewicht, anders winnen oude recepten altijd.
defined('MAX_RECENCY_WEIGHT') or define('MAX_RECENCY_WEIGHT', 30.0);

// 0 = maandag ... 6 = zondag. 4 = vrijdag = junkfood.
defined('JUNK_DAY_INDEX') or define('JUNK_DAY_INDEX', 4);
defined('JUNK_LABEL')     or define('JUNK_LABEL',     'Junkfood-dag');

/* ---------------------------------------------------------------
 * Foutmeldingen
 * --------------------------------------------------------------- */
// Op de server staan fouten uit (bezoekers hoeven geen padnamen te zien).
// Zet DEBUG in config.local.php op true om ze lokaal wel te tonen.
defined('DEBUG') or define('DEBUG', false);

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
