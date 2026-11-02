<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Tests\Support;

use PDO;

/**
 * Builds an in-memory SQLite database with the test schema applied.
 *
 * SQLite rather than MySQL because the suite must run anywhere with ext-pdo_sqlite and
 * no server. The trade-off is honest: this proves the SQL and the mapping, not MySQL
 * dialect specifics. SchemaParityTest asserts the SQLite schema declares the same
 * tables and columns as the MySQL migration that ships.
 */
final class SqliteDatabase
{
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enforced by default in MySQL/InnoDB and off by default in SQLite. Turning it on
        // means a missing parent row fails here too, instead of passing in tests and
        // failing in production.
        $pdo->exec('PRAGMA foreign_keys = ON');

        $schema = file_get_contents(__DIR__ . '/../Resources/schema.sqlite.sql');

        if ($schema === false) {
            throw new \RuntimeException('Test schema could not be read.');
        }

        $pdo->exec($schema);

        return $pdo;
    }
}
