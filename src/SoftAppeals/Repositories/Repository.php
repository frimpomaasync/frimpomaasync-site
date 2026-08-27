<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Database;
use SoftAppeals\Support\Clock;

/**
 * The base every repository extends.
 *
 * Repositories own the SQL. Services own the rules. Controllers own neither.
 * That separation is what keeps a page controller from building a query out of
 * request data, which is the single rule section 9.3 states outright.
 */
abstract class Repository
{
    protected Database $db;
    protected Clock $clock;

    public function __construct(Database $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    /** The table this repository owns. */
    abstract protected function table(): string;

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM ' . $this->db->quoteIdentifier($this->table()) . ' WHERE id = :id',
            ['id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByPublicRef(string $ref): ?array
    {
        return $this->db->one(
            'SELECT * FROM ' . $this->db->quoteIdentifier($this->table()) . ' WHERE public_ref = :r',
            ['r' => $ref]
        );
    }

    public function count(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->table())
        );
    }
}
