<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/**
 * Vervangt {recipe} door de echte tabelnaam, inclusief voorvoegsel.
 *
 * Zo staan de queries vol leesbare namen, terwijl je de database kunt
 * delen met een andere site door DB_PREFIX te zetten.
 */
function sqlTables(string $sql): string
{
    return preg_replace_callback(
        '/\{(\w+)\}/',
        static fn(array $m): string => DB_PREFIX . $m[1],
        $sql
    ) ?? $sql;
}

/**
 * PDO dat die accolades zelf afhandelt, zodat geen enkele aanroep
 * eraan hoeft te denken.
 */
final class Db extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query(sqlTables($query), $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(sqlTables($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(sqlTables($statement));
    }
}

function db(): Db
{
    static $pdo = null;
    if ($pdo instanceof Db) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    try {
        $pdo = new Db($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);

        // Op de server geen details tonen, lokaal juist wel.
        if (defined('DEBUG') && DEBUG) {
            exit('Geen verbinding met de database: ' . $e->getMessage());
        }

        exit('Geen verbinding met de database. Controleer inc/config.php.');
    }

    return $pdo;
}
