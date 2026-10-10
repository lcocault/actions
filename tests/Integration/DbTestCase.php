<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Infrastructure\Config;
use Actions\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

/** Base dédiée (TEST_DB_NAME, défaut actions_test). Échoue explicitement si la base est absente. */
abstract class DbTestCase extends TestCase
{
    protected Config $config;
    protected \PDO $pdo;

    protected function setUp(): void
    {
        $env = Config::processEnvironment();
        $file = $env['ACTIONS_ENV_FILE'] ?? dirname(__DIR__, 2) . '/.env';
        $env += is_file($file) ? \Actions\Infrastructure\EnvFile::parse((string) file_get_contents($file)) : [];
        $env['DB_NAME'] = $env['TEST_DB_NAME'] ?? 'actions_test';
        $env['APP_ENV'] = 'test';
        if ($env['DB_NAME'] !== 'actions_test' && !str_ends_with($env['DB_NAME'], '_test')) {
            self::fail('Garde-fou : les tests d\'intégration exigent une base dont le nom finit par _test.');
        }
        $this->config = Config::fromArray($env);
        try {
            $this->pdo = Database::connect($this->config);
        } catch (\PDOException $e) {
            self::fail('Base de test injoignable (voir README, démarrage local) : ' . $e->getMessage());
        }
    }
}
