<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use Stringable;

/**
 * Query builder.
 *
 * Every value is sent to the database as a bound parameter, and every table,
 * column, operator, join and ORDER BY / GROUP BY fragment is validated, so user
 * input can no longer change the structure of a query. Anything that cannot be
 * validated throws an InvalidArgumentException.
 *
 * For the rare complex case there are two explicit escape hatches, both of
 * which must NEVER receive user input: selectRaw() and raw().
 */
class DB
{
    private const ID = '[A-Za-z_][A-Za-z0-9_]*';
    private const PATH = self::ID . '(?:\.' . self::ID . ')*';
    private const NUM = '-?\d+(?:\.\d+)?';
    private const STR = '\'[A-Za-z0-9 _.:%/-]*\'';
    private const ARG = '(?:\*|(?:DISTINCT\s+)?' . self::PATH . '|' . self::NUM . '|' . self::STR . ')';
    private const FUNCS = 'COUNT|SUM|AVG|MIN|MAX|COALESCE|IFNULL|NULLIF|ROUND|ABS|LOWER|UPPER|LENGTH|TRIM|DATE|YEAR|MONTH|DAY|HOUR';
    private const CALL = '(?:' . self::FUNCS . ')\(\s*' . self::ARG . '(?:\s*,\s*' . self::ARG . ')*\s*\)';
    private const EXPR = '(?:' . self::CALL . '|' . self::PATH . '(?:\.\*)?|\*)';
    private const OPERAND = '(?:' . self::PATH . '|' . self::NUM . '|' . self::STR . ')';
    private const COMPARISON = self::OPERAND . '\s*(?:<=|>=|<>|!=|=|<|>)\s*' . self::OPERAND;

    private static ?PDO $pdo = null;
    private static ?string $driver = null;
    private static ?string $lastTable = null;

    private string $table;
    private string $type = '';
    private string $columns = '*';
    private string $indexHint = '';

    /** @var list<string> */
    private array $joins = [];

    /** @var list<array{0: string, 1: string}> */
    private array $wheres = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $havings = [];

    /** @var list<string> */
    private array $orders = [];

    /** @var list<string> */
    private array $unions = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @var array<string, mixed> column => value (insert / update) */
    private array $data = [];

    /** @var array<string, mixed> placeholder => value (conditions) */
    private array $bindings = [];

    private int $counter = 0;
    private ?PDOStatement $lastStatement = null;

    /** @var array<string, true> placeholders bound to sensitive columns (hidden in the debug toolbar) */
    private array $masked = [];

    private function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * Start a query on a table ("users", "users u", "schema.users").
     * Every call returns an independent builder.
     */
    public static function query(string $table = ''): static
    {
        if ($table === '') {
            $table = self::$lastTable ?? '';
        } else {
            self::assertTable($table);
            self::$lastTable = $table;
        }

        return new static($table);
    }

    /** Use an existing PDO connection (tests, custom setups). */
    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$driver = null;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= (new Connection())->PDO();
    }

    // ------------------------------------------------------------------
    // Statement builders
    // ------------------------------------------------------------------

    /**
     * SELECT columns. Accepts plain columns, aliases and simple aggregates:
     * 'id, name', 'u.name AS user', 'COUNT(*) AS total', 'SUM(amount)'.
     */
    public function select(string $columns = '*'): static
    {
        $this->requireTable();
        $this->reset('select');
        $this->columns = self::columnList($columns, true);

        return $this;
    }

    /**
     * SELECT with an arbitrary expression. UNSAFE: never pass user input.
     */
    public function selectRaw(string $expression): static
    {
        $this->requireTable();
        $this->reset('select');
        $this->columns = $expression;

        return $this;
    }

    public function all(): static
    {
        return $this->select('*');
    }

    public function truncate(): static
    {
        $this->requireTable();
        $this->reset('truncate');

        return $this;
    }

    public function delete(): static
    {
        $this->requireTable();
        $this->reset('delete');

        return $this;
    }

    /**
     * @param array<string, mixed> $data column => value
     */
    public function insert(array $data): static
    {
        $this->requireTable();
        if ($data === []) {
            throw new InvalidArgumentException('Insert data cannot be empty.');
        }

        $this->reset('insert');
        foreach ($data as $column => $value) {
            $this->data[self::assertColumn((string) $column)] = $value;
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $data column => value
     */
    public function update(array $data): static
    {
        $this->requireTable();
        if ($data === []) {
            throw new InvalidArgumentException('Update data cannot be empty.');
        }

        $this->reset('update');
        foreach ($data as $column => $value) {
            $this->data[self::assertColumn((string) $column)] = $value;
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // Clauses
    // ------------------------------------------------------------------

    public function useIndex(string $index): static
    {
        return $this->addIndexHint('USE', $index);
    }

    public function forceIndex(string $index): static
    {
        return $this->addIndexHint('FORCE', $index);
    }

    public function ignoreIndex(string $index): static
    {
        return $this->addIndexHint('IGNORE', $index);
    }

    /**
     * @param string $type  INNER, LEFT, RIGHT, FULL, CROSS (optionally "LEFT OUTER")
     * @param string $table table with optional alias
     * @param string $cond  comparisons such as "u.id = p.user_id AND p.active = 1"
     */
    public function join(string $type, string $table, string $cond): static
    {
        $type = strtoupper(trim((string) preg_replace('/\s+/', ' ', $type)));
        if ($type === '') {
            $type = 'INNER';
        }
        if (!self::matches('(?:INNER|LEFT|RIGHT|FULL|CROSS)(?: OUTER)?', $type)) {
            throw new InvalidArgumentException('Invalid join type.');
        }

        self::assertTable($table);

        $cond = trim($cond);
        if (!self::matches(self::COMPARISON . '(?:\s+(?:AND|OR)\s+' . self::COMPARISON . ')*', $cond)) {
            throw new InvalidArgumentException('Invalid join condition.');
        }

        $this->joins[] = $type . ' JOIN ' . $table . ' ON ' . $cond;

        return $this;
    }

    public function union(string $table, string $columns): static
    {
        return $this->addUnion('UNION', $table, $columns);
    }

    public function unionAll(string $table, string $columns): static
    {
        return $this->addUnion('UNION ALL', $table, $columns);
    }

    /**
     * Add a condition. The first condition becomes WHERE, the next ones AND.
     * The default operator is "="; put another operator after the column:
     * where('status <=', 1). Allowed: = != <> < > <= >= LIKE NOT LIKE.
     * A null value with "=" / "!=" becomes IS NULL / IS NOT NULL.
     */
    public function where(string $column, mixed $value = null): static
    {
        return $this->addCondition('AND', $column, $value);
    }

    public function and(string $column, mixed $value = null): static
    {
        return $this->addCondition('AND', $column, $value);
    }

    public function or(string $column, mixed $value = null): static
    {
        return $this->addCondition('OR', $column, $value);
    }

    public function whereIsNull(string $column): static
    {
        [$expr] = self::parseCondition($column);
        $this->wheres[] = ['AND', $expr . ' IS NULL'];

        return $this;
    }

    public function whereIsNotNull(string $column): static
    {
        [$expr] = self::parseCondition($column);
        $this->wheres[] = ['AND', $expr . ' IS NOT NULL'];

        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        [$expr] = self::parseCondition($column);
        $this->wheres[] = ['AND', $expr . ' LIKE ' . $this->bind($pattern, 'w', $expr)];

        return $this;
    }

    /**
     * @param array<int|string, mixed>|string $value array of values, or a comma separated string
     */
    public function whereIn(string $column, mixed $value): static
    {
        [$expr] = self::parseCondition($column);

        $values = is_array($value)
            ? array_values($value)
            : array_map('trim', explode(',', (string) $value));

        if ($values === []) {
            $this->wheres[] = ['AND', '1 = 0'];

            return $this;
        }

        $holders = [];
        foreach ($values as $item) {
            $holders[] = $this->bind($item, 'w', $expr);
        }

        $this->wheres[] = ['AND', $expr . ' IN (' . implode(', ', $holders) . ')'];

        return $this;
    }

    public function groupBy(string $columns): static
    {
        foreach (self::splitList($columns) as $item) {
            if (!self::isExpr($item, false)) {
                throw new InvalidArgumentException('Invalid GROUP BY expression.');
            }
            $this->groups[] = $item;
        }

        return $this;
    }

    /**
     * having('COUNT(*)', '>', 5), having('total >=', '>=', 5) or having('total', 5).
     */
    public function having(string $column, mixed $operator = '=', mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        [$expr, $parsed] = self::parseCondition($column, $operator);
        $this->havings[] = $expr . ' ' . $parsed . ' ' . $this->bind($value, 'h', $expr);

        return $this;
    }

    /**
     * Sort by one or more columns. $order applies to the last column unless a
     * column carries its own direction ("name ASC, id DESC").
     */
    public function orderBy(string $columns, string $order = 'DESC'): static
    {
        $order = strtoupper(trim($order));
        if ($order !== 'ASC' && $order !== 'DESC') {
            throw new InvalidArgumentException('Sort direction must be ASC or DESC.');
        }

        $items = self::splitList($columns);
        $last = count($items) - 1;

        foreach ($items as $i => $item) {
            $direction = null;
            if (preg_match('~^(.*?)\s+(ASC|DESC)$~iD', $item, $m) === 1) {
                $item = trim($m[1]);
                $direction = strtoupper($m[2]);
            } elseif ($i === $last) {
                $direction = $order;
            }

            if (!self::isExpr($item, false)) {
                throw new InvalidArgumentException('Invalid ORDER BY expression.');
            }

            $this->orders[] = $item . ($direction !== null ? ' ' . $direction : '');
        }

        return $this;
    }

    public function limit(int|string $number): static
    {
        $number = (int) $number;
        if ($number < 0) {
            throw new InvalidArgumentException('LIMIT cannot be negative.');
        }
        $this->limit = $number;

        return $this;
    }

    public function offset(int|string $number): static
    {
        $number = (int) $number;
        if ($number < 0) {
            throw new InvalidArgumentException('OFFSET cannot be negative.');
        }
        $this->offset = $number;

        return $this;
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    /**
     * Run a hand written query. UNSAFE when you concatenate input into it:
     * always pass values through $bindings ("?" or ":name" placeholders).
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int, mixed>
     */
    public function raw(string $query, array $bindings = []): array
    {
        $stmt = self::pdo()->prepare($query);
        self::timed($stmt, $query, $bindings, [], $bindings);
        $this->lastStatement = $stmt;

        if ($stmt->columnCount() === 0) {
            return [];
        }

        $rows = $stmt->fetchAll();
        DebugBar::lastQueryRows(count($rows));

        return $rows;
    }

    /**
     * Run the built SELECT and return all rows.
     *
     * @return array<int, mixed>
     */
    public function get(): array
    {
        $stmt = $this->execute();
        if ($stmt->columnCount() === 0) {
            return [];
        }

        $rows = $stmt->fetchAll();
        DebugBar::lastQueryRows(count($rows));

        return $rows;
    }

    /**
     * First column of the first row (false when there is no row), e.g.
     * select('COUNT(*)')->first(). Use row() to get the whole row.
     */
    public function first(): mixed
    {
        $stmt = $this->execute();

        return $stmt->columnCount() > 0 ? $stmt->fetchColumn() : false;
    }

    /**
     * The first row, or null.
     */
    public function row(): mixed
    {
        $stmt = $this->execute();
        if ($stmt->columnCount() === 0) {
            return null;
        }

        $row = $stmt->fetch();
        DebugBar::lastQueryRows($row === false ? 0 : 1);

        return $row === false ? null : $row;
    }

    /**
     * Number of rows matching the current joins and conditions.
     */
    public function count(): int
    {
        $this->requireTable();
        if ($this->type === '') {
            $this->type = 'select';
        } elseif ($this->type !== 'select') {
            throw new LogicException('count() can only be used on a SELECT query.');
        }

        $clone = clone $this;
        $clone->columns = 'COUNT(*)';
        $clone->orders = [];
        $clone->unions = [];
        $clone->limit = null;
        $clone->offset = null;

        return (int) $clone->first();
    }

    /**
     * Execute INSERT / UPDATE / DELETE / TRUNCATE.
     */
    public function save(): bool
    {
        $this->execute();

        return true;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return self::pdo()->lastInsertId($name);
    }

    /** Rows affected by the last executed statement. */
    public function rowCount(): int
    {
        return $this->lastStatement?->rowCount() ?? 0;
    }

    /** The SQL that will be executed (placeholders, no values). */
    public function toSql(): string
    {
        return match ($this->type) {
            'select' => $this->buildSelect(),
            'insert' => $this->buildInsert(),
            'update' => $this->buildUpdate(),
            'delete' => 'DELETE FROM ' . $this->table . $this->buildWhere(),
            'truncate' => $this->buildTruncate(),
            default => throw new LogicException('No query built yet: call select(), insert(), update() or delete() first.'),
        };
    }

    /**
     * @return array<string, mixed> placeholder => value
     */
    public function getBindings(): array
    {
        $bindings = $this->bindings;
        $prefix = $this->type === 'insert' ? ':i_' : ':s_';

        foreach ($this->data as $column => $value) {
            $bindings[$prefix . $column] = $value;
        }

        return $bindings;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function execute(): PDOStatement
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();
        $stmt = self::pdo()->prepare($sql);

        foreach ($bindings as $name => $value) {
            [$prepared, $type] = self::prepareValue($value);
            $stmt->bindValue($name, $prepared, $type);
        }

        self::timed($stmt, $sql, $bindings, $this->maskedPlaceholders());

        return $this->lastStatement = $stmt;
    }

    /**
     * Execute a statement. In development the time, the SQL and the bound values
     * (minus sensitive ones) are reported to the debug toolbar.
     *
     * @param array<int|string, mixed> $bindings values shown in the toolbar
     * @param list<string>             $masked   placeholders to hide
     * @param array<int|string, mixed>|null $params passed to execute() (raw queries)
     */
    private static function timed(PDOStatement $stmt, string $sql, array $bindings, array $masked, ?array $params = null): void
    {
        if (!DebugBar::enabled()) {
            if ($params === null) {
                $stmt->execute();
            } else {
                $stmt->execute($params);
            }

            return;
        }

        $started = microtime(true);

        try {
            if ($params === null) {
                $stmt->execute();
            } else {
                $stmt->execute($params);
            }
        } catch (\Throwable $e) {
            DebugBar::query($sql, $bindings, (microtime(true) - $started) * 1000, null, $masked, $e->getMessage());

            throw $e;
        }

        DebugBar::query($sql, $bindings, (microtime(true) - $started) * 1000, $stmt->columnCount() > 0 ? null : $stmt->rowCount(), $masked);
    }

    /**
     * @return list<string>
     */
    private function maskedPlaceholders(): array
    {
        $names = array_keys($this->masked);
        $prefix = $this->type === 'insert' ? ':i_' : ':s_';

        foreach (array_keys($this->data) as $column) {
            if (DebugView::isSensitive((string) $column)) {
                $names[] = $prefix . $column;
            }
        }

        return $names;
    }

    private function buildSelect(): string
    {
        $sql = 'SELECT ' . $this->columns . ' FROM ' . $this->table . $this->indexHint;

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join;
        }

        $sql .= $this->buildWhere();

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        foreach ($this->unions as $union) {
            $sql .= ' ' . $union;
        }

        $order = $this->orders === [] ? '' : ' ORDER BY ' . implode(', ', $this->orders);

        if ($this->limit === null && $this->offset === null) {
            return $sql . $order;
        }

        if (self::driver() === 'sqlsrv') {
            if ($order === '') {
                $order = ' ORDER BY (SELECT NULL)';
            }
            $sql .= $order . ' OFFSET ' . (int) $this->offset . ' ROWS';

            return $this->limit !== null ? $sql . ' FETCH NEXT ' . $this->limit . ' ROWS ONLY' : $sql;
        }

        return $sql . $order
            . ' LIMIT ' . ($this->limit ?? PHP_INT_MAX)
            . ($this->offset !== null ? ' OFFSET ' . $this->offset : '');
    }

    private function buildInsert(): string
    {
        $columns = array_keys($this->data);

        return 'INSERT INTO ' . $this->table
            . ' (' . implode(', ', $columns) . ') VALUES (:i_' . implode(', :i_', $columns) . ')';
    }

    private function buildUpdate(): string
    {
        $sets = [];
        foreach (array_keys($this->data) as $column) {
            $sets[] = $column . ' = :s_' . $column;
        }

        return 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . $this->buildWhere();
    }

    private function buildTruncate(): string
    {
        return self::driver() === 'sqlite'
            ? 'DELETE FROM ' . $this->table
            : 'TRUNCATE TABLE ' . $this->table;
    }

    private function buildWhere(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $sql = ' WHERE ';
        foreach ($this->wheres as $i => [$bool, $condition]) {
            $sql .= ($i === 0 ? '' : ' ' . $bool . ' ') . $condition;
        }

        return $sql;
    }

    private function reset(string $type): void
    {
        $this->type = $type;
        $this->columns = '*';
        $this->indexHint = '';
        $this->joins = [];
        $this->wheres = [];
        $this->groups = [];
        $this->havings = [];
        $this->orders = [];
        $this->unions = [];
        $this->limit = null;
        $this->offset = null;
        $this->data = [];
        $this->bindings = [];
        $this->masked = [];
        $this->counter = 0;
    }

    private function requireTable(): void
    {
        if ($this->table === '') {
            throw new LogicException('No table selected: use DB::query("table").');
        }
    }

    private function addCondition(string $bool, string $column, mixed $value): static
    {
        if (is_array($value)) {
            throw new InvalidArgumentException('Use whereIn() for array values.');
        }

        [$expr, $operator] = self::parseCondition($column);

        if ($value === null && ($operator === '=' || $operator === '!=' || $operator === '<>')) {
            $sql = $expr . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
        } else {
            $sql = $expr . ' ' . $operator . ' ' . $this->bind($value, 'w', $expr);
        }

        $this->wheres[] = [$bool, $sql];

        return $this;
    }

    private function addIndexHint(string $hint, string $index): static
    {
        $names = [];
        foreach (self::splitList($index) as $name) {
            if (!self::matches(self::ID, $name)) {
                throw new InvalidArgumentException('Invalid index name.');
            }
            $names[] = $name;
        }

        $this->indexHint .= ' ' . $hint . ' INDEX (' . implode(', ', $names) . ')';

        return $this;
    }

    private function addUnion(string $keyword, string $table, string $columns): static
    {
        self::assertTable($table);
        $this->unions[] = $keyword . ' SELECT ' . self::columnList($columns, true) . ' FROM ' . $table;

        return $this;
    }

    private function bind(mixed $value, string $prefix = 'w', string $column = ''): string
    {
        $name = ':' . $prefix . $this->counter++;
        $this->bindings[$name] = $value;

        if ($column !== '' && DebugView::isSensitive($column)) {
            $this->masked[$name] = true;
        }

        return $name;
    }

    private static function driver(): string
    {
        return self::$driver ??= (string) self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @return array{0: mixed, 1: int} value and PDO parameter type
     */
    private static function prepareValue(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value, PDO::PARAM_BOOL],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_float($value) => [(string) $value, PDO::PARAM_STR],
            is_string($value) => [$value, PDO::PARAM_STR],
            $value instanceof DateTimeInterface => [$value->format('Y-m-d H:i:s'), PDO::PARAM_STR],
            $value instanceof Stringable => [(string) $value, PDO::PARAM_STR],
            default => throw new InvalidArgumentException('Unsupported value type for a query parameter.'),
        };
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    private static function matches(string $body, string $value): bool
    {
        return preg_match('~^(?:' . $body . ')$~iD', $value) === 1;
    }

    private static function assertTable(string $table): void
    {
        if (!self::matches(self::PATH . '(?:\s+(?:AS\s+)?' . self::ID . ')?', $table)) {
            throw new InvalidArgumentException('Invalid table name.');
        }
    }

    private static function assertColumn(string $column): string
    {
        if (!self::matches(self::ID, $column)) {
            throw new InvalidArgumentException('Invalid column name.');
        }

        return $column;
    }

    private static function isExpr(string $expr, bool $allowAlias): bool
    {
        if ($expr === '' || strlen($expr) > 200) {
            return false;
        }

        return self::matches(self::EXPR . ($allowAlias ? '(?:\s+(?:AS\s+)?' . self::ID . ')?' : ''), $expr);
    }

    private static function columnList(string $list, bool $allowAlias): string
    {
        $items = self::splitList($list);

        foreach ($items as $item) {
            if (!self::isExpr($item, $allowAlias)) {
                throw new InvalidArgumentException('Invalid column expression. Use selectRaw() for complex expressions.');
            }
        }

        return implode(', ', $items);
    }

    /**
     * Split on commas that are not inside parentheses or quotes.
     *
     * @return list<string>
     */
    private static function splitList(string $list): array
    {
        $items = [];
        $buffer = '';
        $depth = 0;
        $quoted = false;

        for ($i = 0, $length = strlen($list); $i < $length; $i++) {
            $char = $list[$i];

            if ($char === "'") {
                $quoted = !$quoted;
            } elseif (!$quoted) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
            }

            if ($char === ',' && $depth === 0 && !$quoted) {
                $items[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $items[] = trim($buffer);

        return $items;
    }

    /**
     * Split "column <=" into the validated expression and operator.
     *
     * @return array{0: string, 1: string}
     */
    private static function parseCondition(string $column, mixed $defaultOperator = '='): array
    {
        $column = trim($column);

        // Symbol operators may touch the column ("status<="), word operators need a space
        // so a column such as "dislike" is not mistaken for "dis" + LIKE.
        if (preg_match('~^(.+?)(?:\s*(<=|>=|<>|!=|=|<|>)|\s+(NOT\s+LIKE|LIKE))$~iD', $column, $m) === 1) {
            $expr = trim($m[1]);
            $found = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
            $operator = strtoupper((string) preg_replace('/\s+/', ' ', $found));
        } else {
            $expr = $column;
            $operator = strtoupper(trim((string) $defaultOperator));
        }

        if (!self::isExpr($expr, false)) {
            throw new InvalidArgumentException('Invalid column expression.');
        }

        if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'], true)) {
            throw new InvalidArgumentException('Invalid comparison operator.');
        }

        return [$expr, $operator];
    }
}
