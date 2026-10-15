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

    protected const MIGRATIONS = __DIR__ . '/../../database/migrations';

    /** Supprime tout le schéma métier et le suivi des migrations (base de test seulement). */
    protected function dropSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, projects, schema_migrations CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
    }

    /** Repart d'une base vide puis applique toutes les migrations (six projets créés par 0004). */
    protected function migrateFresh(): void
    {
        $this->dropSchema();
        (new \Actions\Infrastructure\Migrator($this->pdo, self::MIGRATIONS))->migrate();
    }

    /** Identifiant d'un projet par son nom exact (pour les INSERT bruts). */
    protected function projectId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM projects WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Applique seulement les migrations jusqu'à `$last` incluse (état intermédiaire pour tester une migration avec des
     * données). Le schéma doit être vide. Un appel ultérieur de migrate() sur le dossier complet applique la suite.
     *
     * @return list<string> versions appliquées
     */
    protected function migrateTo(string $last): array
    {
        $dir = sys_get_temp_dir() . '/actions-mig-to-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (glob(self::MIGRATIONS . '/*.sql') ?: [] as $path) {
                if (basename($path, '.sql') <= $last) {
                    copy($path, $dir . '/' . basename($path));
                }
            }

            return (new \Actions\Infrastructure\Migrator($this->pdo, $dir))->migrate();
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
