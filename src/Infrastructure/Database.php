<?php

declare(strict_types=1);

namespace Actions\Infrastructure;

final class Database
{
    /** @throws \PDOException */
    public static function connect(Config $config): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=3',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
        );

        return new \PDO($dsn, $config->dbUser, $config->dbPassword, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
}
