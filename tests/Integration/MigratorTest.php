<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Infrastructure\Migrator;

final class MigratorTest extends DbTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
        $this->dir = sys_get_temp_dir() . '/actions-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
        if (isset($this->pdo)) {
            $this->reset();
        }
    }

    private function reset(): void
    {
        // Les tables métier (migration 0001) appartiennent à TaskSchemaTest, qui les recrée : on repart d'une base vide.
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, projects, schema_migrations, mig_test_a, mig_test_b CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
    }

    private function tables(): array
    {
        return $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY 1")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testEmptyDirectoryCreatesOnlyTrackingTableAndIsReplayable(): void
    {
        $m = new Migrator($this->pdo, $this->dir);
        self::assertSame([], $m->migrate());
        self::assertSame([], $m->migrate());
        self::assertSame(['schema_migrations'], $this->tables());
        self::assertSame(['applied' => [], 'pending' => []], $m->status());
    }

    public function testAppliesInOrderOnceAndTracksChecksum(): void
    {
        file_put_contents($this->dir . '/0002_b.sql', 'CREATE TABLE mig_test_b (id int);');
        file_put_contents($this->dir . '/0001_a.sql', 'CREATE TABLE mig_test_a (id int);');
        $m = new Migrator($this->pdo, $this->dir);
        self::assertSame(['applied' => [], 'pending' => ['0001_a', '0002_b']], $m->status());
        self::assertSame(['0001_a', '0002_b'], $m->migrate());
        self::assertSame([], $m->migrate());
        $row = $this->pdo->query("SELECT checksum FROM schema_migrations WHERE version='0001_a'")->fetchColumn();
        self::assertSame(hash('sha256', 'CREATE TABLE mig_test_a (id int);'), $row);
    }

    public function testModifiedAppliedMigrationFails(): void
    {
        file_put_contents($this->dir . '/0001_a.sql', 'CREATE TABLE mig_test_a (id int);');
        (new Migrator($this->pdo, $this->dir))->migrate();
        file_put_contents($this->dir . '/0001_a.sql', 'CREATE TABLE mig_test_a (id int, x int);');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/modifiée/');
        (new Migrator($this->pdo, $this->dir))->migrate();
    }

    public function testFailingMigrationLeavesNoPartialState(): void
    {
        file_put_contents($this->dir . '/0001_a.sql', 'CREATE TABLE mig_test_a (id int);');
        file_put_contents($this->dir . '/0002_b.sql', 'CREATE TABLE mig_test_b (id int); SELECT * FROM table_inexistante;');
        try {
            (new Migrator($this->pdo, $this->dir))->migrate();
            self::fail('Exception attendue');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0002_b', $e->getMessage());
        }
        self::assertSame(['mig_test_a', 'schema_migrations'], $this->tables());
        self::assertSame(['0001_a'], $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testInvalidFileNameIsRejected(): void
    {
        file_put_contents($this->dir . '/pas_numerote.sql', 'SELECT 1;');
        $this->expectException(\RuntimeException::class);
        (new Migrator($this->pdo, $this->dir))->migrate();
    }
}
