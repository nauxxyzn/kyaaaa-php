<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

class Connection
{
    private ?PDO $connect = null;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed>|null $config defaults to \Core\Conf\Database::config()
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (new \Core\Conf\Database())->config();
    }

    /**
     * Open (once) and return the PDO connection.
     *
     * @throws RuntimeException when the connection fails. Connection details
     *         (host, user, ...) are logged but never put in the exception message.
     */
    public function PDO(?string $driver = null): PDO
    {
        if ($this->connect !== null && $driver === null) {
            return $this->connect;
        }

        $driver = strtolower($driver ?? (string) ($this->config['driver'] ?? 'mysql'));

        try {
            $this->connect = match ($driver) {
                'mysql' => $this->connectMySQL(),
                'pgsql' => $this->connectPostgreSQL(),
                'sqlsrv' => $this->connectSQLServer(),
                'sqlite' => $this->connectSQLite(),
                default => throw new InvalidArgumentException('Unsupported database driver.'),
            };
        } catch (PDOException $e) {
            error_log('[kyaaaa] Database connection failed: ' . str_replace(["\r", "\n"], ' ', $e->getMessage()));

            throw new RuntimeException('Database connection failed.', 0, Bootstrap::isDebug() ? $e : null);
        }

        return $this->connect;
    }

    /**
     * @return array<int, mixed>
     */
    private function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    private function value(string $key, string $default = ''): string
    {
        $value = (string) ($this->config[$key] ?? $default);

        // DSN values are separated by ";", so they must never contain one.
        if (preg_match('/[;\x00-\x1F]/', $value) === 1) {
            throw new InvalidArgumentException("Invalid database setting: {$key}.");
        }

        return $value;
    }

    private function connectMySQL(): PDO
    {
        $charset = $this->value('charset', 'utf8mb4');
        if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
            throw new InvalidArgumentException('Invalid database charset.');
        }

        $options = $this->options() + [PDO::ATTR_EMULATE_PREPARES => false];

        $ca = (string) ($this->config['ssl_ca'] ?? '');
        if ($ca !== '') {
            // PDO::MYSQL_ATTR_* moved to Pdo\Mysql in newer PHP versions.
            $constant = defined('Pdo\Mysql::ATTR_SSL_CA') ? 'Pdo\Mysql::ATTR_SSL_CA' : 'PDO::MYSQL_ATTR_SSL_CA';
            $options[constant($constant)] = $ca;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->value('host', '127.0.0.1'),
            (int) ($this->config['port'] ?? 3306),
            $this->value('database'),
            $charset
        );

        return new PDO($dsn, (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''), $options);
    }

    private function connectPostgreSQL(): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->value('host', '127.0.0.1'),
            (int) ($this->config['port'] ?? 5432),
            $this->value('database')
        );

        return new PDO(
            $dsn,
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
            $this->options() + [PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    private function connectSQLServer(): PDO
    {
        $dsn = sprintf(
            'sqlsrv:Server=%s,%d;Database=%s',
            $this->value('host', '127.0.0.1'),
            (int) ($this->config['port'] ?? 1433),
            $this->value('database')
        );

        return new PDO($dsn, (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''), $this->options());
    }

    private function connectSQLite(): PDO
    {
        $path = (string) ($this->config['sqlite_path'] ?? '');
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('sqlite_path is required for the sqlite driver.');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, $this->options());
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
