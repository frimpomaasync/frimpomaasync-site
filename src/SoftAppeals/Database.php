<?php
declare(strict_types=1);

namespace SoftAppeals;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * The database layer.
 *
 * PDO only, prepared statements only, exceptions on. No page controller builds
 * SQL from request data, and nothing in this class interpolates a value into a
 * statement. Identifiers that must be interpolated (a table name in a migration
 * helper) go through quoteIdentifier and are checked against a pattern first.
 *
 * The driver is behind the DSN on purpose. MySQL is confirmed available on her
 * plan and is the production target; SQLite is what the migrations are proved
 * against locally, because there is no PHP or MySQL on the machine this is
 * written on. The schema is written to run unchanged on both, which is why
 * there are no ENUM columns, no MySQL JSON functions, and no stored procedures.
 */
final class Database
{
    private PDO $pdo;
    private string $driver;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function fromConfig(Config $config): self
    {
        if (!$config->hasDatabase()) {
            throw new RuntimeException(
                'Soft Appeals has no database configured. Set SA_DB_DSN in the private config file.'
            );
        }
        return self::connect(
            $config->string('SA_DB_DSN'),
            $config->string('SA_DB_USER'),
            $config->string('SA_DB_PASSWORD')
        );
    }

    public static function connect(string $dsn, string $user = '', string $password = ''): self
    {
        try {
            $pdo = new PDO($dsn, $user === '' ? null : $user, $password === '' ? null : $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // The DSN can carry the host and the database name. Neither belongs
            // in an error that might reach a page, so the original message is
            // dropped here and the correlation reference carries the detail.
            throw new RuntimeException('The Soft Appeals database could not be reached.', 0, $e);
        }

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            // Foreign keys are off by default in SQLite. Without this the
            // constraints in the migrations would be decorative locally and
            // enforced in production, which is the worst of both.
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }

        if ($driver === 'mysql') {
            // Reject a zero date, a truncated string, and a division by zero
            // rather than storing a mangled value. STRICT_ALL_TABLES is what
            // stops a silent truncation of a name into a Subject line.
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $pdo->exec("SET SESSION time_zone = '+00:00'");
        }

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /** @param array<string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function exists(string $sql, array $params = []): bool
    {
        return $this->one($sql, $params) !== null;
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): void
    {
        $table = $this->quoteIdentifier($table);
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $quoted = array_map([$this, 'quoteIdentifier'], $columns);

        $this->run(
            'INSERT INTO ' . $table . ' (' . implode(', ', $quoted) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')',
            $row
        );
    }

    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $changes, array $where): int
    {
        if ($changes === [] || $where === []) {
            throw new RuntimeException('An update needs both changes and a where clause.');
        }

        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $sets[] = $this->quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = $this->quoteIdentifier($column) . ' = :where_' . $column;
            $params['where_' . $column] = $value;
        }

        return $this->run(
            'UPDATE ' . $this->quoteIdentifier($table)
            . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . implode(' AND ', $conditions),
            $params
        )->rowCount();
    }

    /**
     * Run a closure inside a transaction. A nested call joins the outer one
     * rather than starting a second, because a signature write happens inside
     * the transition that authorised it and neither should commit alone.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tableExists(string $table): bool
    {
        if ($this->isSqlite()) {
            return $this->one(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t",
                ['t' => $table]
            ) !== null;
        }
        return $this->one(
            'SELECT table_name FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $table]
        ) !== null;
    }

    /**
     * The only place an identifier is ever interpolated. Anything outside
     * [A-Za-z0-9_] is refused rather than escaped, because no table or column in
     * this schema needs another character and a name that does is a bug.
     */
    public function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Refusing to use "' . $name . '" as a database identifier.');
        }
        return $this->isSqlite() ? '"' . $name . '"' : '`' . $name . '`';
    }
}
