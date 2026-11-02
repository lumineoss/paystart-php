<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Tests\Unit;

use Lumineoss\Paystart\Bridge\Standalone\Tests\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the one real risk in maintaining two schemas by hand.
 *
 * The shipped migration is MySQL; the test schema is SQLite. They are edited
 * separately, so a column added to one and forgotten in the other passes every test
 * (SQLite runs) and breaks production (MySQL is what deploys).
 *
 * This test cannot prove the dialects are semantically identical. It proves the tables
 * and columns match, which is the mistake that actually happens.
 */
final class SchemaParityTest extends TestCase
{
    private const string MYSQL_MIGRATION = __DIR__ . '/../../resources/migrations/0001_init.sql';

    private const string SQLITE_SCHEMA = __DIR__ . '/../Resources/schema.sqlite.sql';

    #[Test]
    public function both_schemas_declare_the_same_tables(): void
    {
        $mysql = $this->mysqlTables($this->read(self::MYSQL_MIGRATION));
        $sqlite = $this->sqliteTables($this->read(self::SQLITE_SCHEMA));

        self::assertSame($mysql, $sqlite, 'the MySQL migration and the SQLite test schema declare different tables');
    }

    #[Test]
    public function both_schemas_declare_the_same_columns_per_table(): void
    {
        $mysql = $this->read(self::MYSQL_MIGRATION);
        $sqlite = $this->read(self::SQLITE_SCHEMA);

        foreach ($this->mysqlTables($mysql) as $table) {
            self::assertSame(
                $this->mysqlColumns($mysql, $table),
                $this->sqliteColumns($sqlite, $table),
                \sprintf('table "%s" declares different columns in the two schemas', $table),
            );
        }
    }

    /**
     * Every column must survive the SQLite schema too, checked against a real database
     * rather than the text, so a typo in the test file itself cannot hide a mismatch.
     */
    #[Test]
    public function the_sqlite_schema_actually_creates_every_declared_column(): void
    {
        $pdo = SqliteDatabase::create();
        $sqlite = $this->read(self::SQLITE_SCHEMA);

        foreach ($this->mysqlTables($this->read(self::MYSQL_MIGRATION)) as $table) {
            $statement = $pdo->query(\sprintf('PRAGMA table_info(%s)', $table));
            self::assertNotFalse($statement);

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $actual = array_map(static fn (array $r): string => (string) $r['name'], $rows);
            sort($actual);

            self::assertSame($this->sqliteColumns($sqlite, $table), $actual, \sprintf('table "%s" did not create as declared', $table));
        }
    }

    /**
     * Names the unique indexes the persistence guarantees depend on.
     *
     * These are not cosmetic: the duplicate-webhook path relies on the dedup key, and
     * the double-refund path relies on the payment/idempotency pair. If either index
     * goes missing, the atomicity claims in the repositories become false while every
     * test still passes.
     */
    #[Test]
    public function the_uniqueness_guarantees_exist_in_both_schemas(): void
    {
        $mysql = $this->read(self::MYSQL_MIGRATION);
        $sqlite = $this->read(self::SQLITE_SCHEMA);

        foreach ([
            'uq_webhook_events_dedup',
            'uq_refunds_payment_idempotency',
            'uq_refunds_payment_position',
            'uq_attempts_payment_position',
            'uq_instructions_attempt_position',
            'uq_outbox_event_id',
        ] as $index) {
            self::assertStringContainsString($index, $mysql, \sprintf('MySQL migration is missing unique index "%s"', $index));
            self::assertStringContainsString($index, $sqlite, \sprintf('SQLite schema is missing unique index "%s"', $index));
        }
    }

    /**
     * The deduplication index must be scoped by provider, matching the port's contract.
     * A single-column index would let one provider suppress another provider's event.
     */
    #[Test]
    public function the_webhook_dedup_index_is_scoped_by_provider(): void
    {
        $pdo = SqliteDatabase::create();

        $statement = $pdo->query('PRAGMA index_info(uq_webhook_events_dedup)');
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_map(static fn (array $r): string => (string) $r['name'], $rows);
        sort($columns);

        self::assertSame(['deduplication_key', 'provider'], $columns);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail(\sprintf('Could not read %s.', $path));
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function mysqlTables(string $sql): array
    {
        preg_match_all('/CREATE\s+TABLE\s+`?([a-z0-9_]+)`?/i', $sql, $matches);

        $tables = array_values(array_unique($matches[1] ?? []));
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function sqliteTables(string $sql): array
    {
        preg_match_all('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)([a-z0-9_]+)/i', $sql, $matches);

        $tables = array_values(array_unique($matches[1] ?? []));
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function mysqlColumns(string $sql, string $table): array
    {
        $body = $this->tableBody($sql, $table, '/CREATE\s+TABLE\s+`?' . preg_quote($table, '/') . '`?\s*\((.*?)\)\s*ENGINE/is');

        $columns = [];

        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);

            // Skip constraint and index declarations; only column definitions matter.
            if ($trimmed === '' || preg_match('/^(PRIMARY|UNIQUE|INDEX|KEY|CONSTRAINT|FOREIGN)\b/i', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^`?([a-z0-9_]+)`?\s+[A-Z]/i', $trimmed, $m) === 1) {
                $columns[] = $m[1];
            }
        }

        sort($columns);

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function sqliteColumns(string $sql, string $table): array
    {
        $body = $this->tableBody($sql, $table, '/CREATE\s+TABLE\s+' . preg_quote($table, '/') . '\s*\((.*?)\n\);/is');

        $columns = [];

        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || preg_match('/^(PRIMARY|UNIQUE|INDEX|KEY|CONSTRAINT|FOREIGN)\b/i', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^([a-z0-9_]+)\s+[A-Z]/i', $trimmed, $m) === 1) {
                $columns[] = $m[1];
            }
        }

        sort($columns);

        return $columns;
    }

    private function tableBody(string $sql, string $table, string $pattern): string
    {
        if (preg_match($pattern, $sql, $matches) !== 1) {
            self::fail(\sprintf('Could not find the definition of "%s".', $table));
        }

        return $matches[1];
    }
}
