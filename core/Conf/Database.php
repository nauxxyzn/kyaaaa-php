<?php

declare(strict_types=1);

namespace Core\Conf;

use Core\Conf\Kyaaaa\Env;

class Database
{
    /**
     * 'driver' = mysql, pgsql, sqlsrv, sqlite
     *  mysql  - default port 3306
     *  pgsql  - default port 5432
     *  sqlsrv - default port 1433
     *
     * 'sqlite_path' - path to your sqlite database file. If you use sqlite you
     * only need to change 'driver' & 'sqlite_path'.
     *
     * 'ssl_ca' (mysql, optional) - path to a CA certificate for TLS connections.
     *
     * Values come from .env (DB_DRIVER, DB_HOST, DB_DATABASE, DB_USERNAME,
     * DB_PASSWORD, DB_PORT, DB_SQLITE_PATH, DB_SSL_CA) so no password has to be
     * committed to the repository.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'driver' => (string) Env::get('DB_DRIVER', 'mysql'),
            'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
            'database' => (string) Env::get('DB_DATABASE', 'kyaaaa_db'),
            'username' => (string) Env::get('DB_USERNAME', 'root'),
            'password' => (string) Env::get('DB_PASSWORD', ''),
            'port' => (int) Env::get('DB_PORT', '3306'),
            'sqlite_path' => (string) Env::get('DB_SQLITE_PATH', ''),
            'charset' => 'utf8mb4',
            'ssl_ca' => (string) Env::get('DB_SSL_CA', ''),
        ];
    }
}
