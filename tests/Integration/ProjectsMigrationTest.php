<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Infrastructure\Migrator;
use Actions\Infrastructure\ProjectConflictReport;

/**
 * Migration 0004 (projets) et rapport de conflits `bin/report-project-conflicts`, contre PostgreSQL réel
 * (base actions_test, schéma recréé dans chaque test).
 */
final class ProjectsMigrationTest extends DbTestCase
{
    private const BEFORE = '0003_task_project_conclusions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    private function atVersion0003(): void
    {
        $this->migrateTo(self::BEFORE);
    }

    private function migrate(): array
    {
        return (new Migrator($this->pdo, self::MIGRATIONS))->migrate();
    }

    /** Insère une tâche au schéma d'avant 0004; renvoie son id. */
    private function oldTask(string $title, string $category, ?string $project = null, string $status = 'todo'): int
    {
        $done = $status === 'done' ? 'now()' : 'NULL';
        $stmt = $this->pdo->prepare("INSERT INTO tasks (title, category, project, status, completed_at) VALUES (?, ?, ?, ?, $done) RETURNING id");
        $stmt->execute([$title, $category, $project, $status]);

        return (int) $stmt->fetchColumn();
    }

    private function assertViolation(string $sqlstate, string $constraint, callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            self::assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());
            self::assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        self::fail("Violation $sqlstate / $constraint attendue.");
    }

    private function refusalMessage(): string
    {
        try {
            $this->migrate();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        self::fail('Refus de la migration attendu.');
    }

    // --- Schéma obtenu ---

    public function testFreshDatabaseHasTheSixProjects(): void
    {
        self::assertSame(['0001_tasks_and_dependencies', '0002_task_status', '0003_task_project_conclusions', '0004_projects'], $this->migrate());
        $rows = $this->pdo->query('SELECT name, category FROM projects ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'MS-HR' => 'Pro', 'Jardin/Maison' => 'Perso', 'OPF' => 'Perso', 'Erendil' => 'Perso',
            'Divers Pro' => 'Pro', 'Divers Perso' => 'Perso',
        ], $rows);
        $cols = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'tasks'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('category', $cols);
        self::assertNotContains('project', $cols);
        self::assertSame('NO', $this->pdo->query("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'tasks' AND column_name = 'project_id'")->fetchColumn());
        self::assertSame([], $this->migrate(), 'rejouable : rien à appliquer');
    }

    public function testProjectConstraints(): void
    {
        $this->migrate();
        $insert = fn (string $name, string $category) => $this->pdo->prepare('INSERT INTO projects (name, category) VALUES (?, ?)')->execute([$name, $category]);
        foreach (['', '   ', str_repeat('a', 256)] as $name) {
            $this->assertViolation('23514', 'projects_name_not_blank', fn () => $insert($name, 'Pro'));
        }
        $insert(str_repeat('é', 255), 'Pro'); // 255 caractères : accepté
        foreach (['pro', 'PERSO', 'Autre', ''] as $category) {
            $this->assertViolation('23514', 'projects_category_valid', fn () => $insert('Nouveau', $category));
        }
        $this->assertViolation('23502', 'name', fn () => $this->pdo->exec("INSERT INTO projects (category) VALUES ('Pro')"));
        $this->assertViolation('23502', 'category', fn () => $this->pdo->exec("INSERT INTO projects (name) VALUES ('X')"));
    }

    public function testNameUniquenessIgnoresCase(): void
    {
        $this->migrate();
        $insert = fn (string $name) => $this->pdo->prepare("INSERT INTO projects (name, category) VALUES (?, 'Perso')")->execute([$name]);
        foreach (['opf', 'Opf', 'OPF', 'ms-hr', 'divers PRO'] as $name) {
            $this->assertViolation('23505', 'projects_name_lower_key', fn () => $insert($name));
        }
        $insert('OPF 2'); // un nom différent passe
        self::assertSame(7, (int) $this->pdo->query('SELECT count(*) FROM projects')->fetchColumn());
    }

    public function testForeignKeyRefusesDeletingAUsedProjectAndUnknownProject(): void
    {
        $this->migrate();
        $pid = $this->projectId('OPF');
        $this->pdo->exec("INSERT INTO tasks (title, project_id) VALUES ('t', $pid)");
        $this->assertViolation('23503', 'tasks_project_id_fkey', fn () => $this->pdo->exec("DELETE FROM projects WHERE id = $pid"));
        $this->assertViolation('23503', 'tasks_project_id_fkey', fn () => $this->pdo->exec("INSERT INTO tasks (title, project_id) VALUES ('u', 999999)"));
        $this->pdo->exec('DELETE FROM tasks');
        $this->pdo->exec("DELETE FROM projects WHERE id = $pid"); // sans tâche : supprimable
        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM projects WHERE name = 'OPF'")->fetchColumn());
    }

    // --- Migration avec des données ---

    public function testMigrationAttachesTasksWithoutLosingAnythingElse(): void
    {
        $this->atVersion0003();
        $a = $this->oldTask('Pro sans projet', 'Pro');
        $b = $this->oldTask('Perso sans projet', 'Perso');
        $c = $this->oldTask('MS-HR Pro', 'Pro', 'MS-HR');
        $d = $this->oldTask('Jardin', 'Perso', 'Jardin/Maison', 'done');
        $e = $this->oldTask('OPF', 'Perso', 'OPF');
        $f = $this->oldTask('Erendil', 'Perso', 'Erendil');
        $this->pdo->exec("UPDATE tasks SET updated_at = '2026-01-02 03:04:05+00', description = 'ctx', conclusions = 'k' WHERE id = $d");
        $this->pdo->exec("INSERT INTO task_dependencies (task_id, required_task_id) VALUES ($a, $b), ($c, $d)");
        $cols = 'id, title, description, conclusions, status, completed_at, created_at, updated_at';
        $before = $this->pdo->query("SELECT $cols FROM tasks ORDER BY id")->fetchAll();

        self::assertSame(['0004_projects'], $this->migrate());

        self::assertSame($before, $this->pdo->query("SELECT $cols FROM tasks ORDER BY id")->fetchAll(), 'aucune tâche perdue ni modifiée (updated_at, état, clôture conservés)');
        $byTask = $this->pdo->query('SELECT t.id, p.name, p.category FROM tasks t JOIN projects p ON p.id = t.project_id ORDER BY t.id')->fetchAll();
        self::assertSame([
            [$a, 'Divers Pro', 'Pro'], [$b, 'Divers Perso', 'Perso'], [$c, 'MS-HR', 'Pro'],
            [$d, 'Jardin/Maison', 'Perso'], [$e, 'OPF', 'Perso'], [$f, 'Erendil', 'Perso'],
        ], array_map(static fn (array $r): array => [(int) $r['id'], $r['name'], $r['category']], $byTask));
        self::assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM task_dependencies')->fetchColumn());
        self::assertSame(['0001_tasks_and_dependencies', '0002_task_status', '0003_task_project_conclusions', '0004_projects'], (new Migrator($this->pdo, self::MIGRATIONS))->status()['applied']);
    }

    public function testConflictStopsTheMigrationWithoutPartialStateThenReplaysAfterArbitration(): void
    {
        $this->atVersion0003();
        $ok = $this->oldTask('Ok', 'Pro', 'MS-HR');
        $c1 = $this->oldTask('Conflit un', 'Perso', 'MS-HR');
        $c2 = $this->oldTask('Conflit deux', 'Pro', 'OPF');
        $c3 = $this->oldTask('Conflit trois', 'Pro', 'Erendil', 'done');
        $this->oldTask('Sans projet', 'Perso');

        $message = $this->refusalMessage();

        self::assertStringContainsString('0004', $message);
        self::assertStringContainsString('3 tâche(s)', $message);
        foreach ([$c1, $c2, $c3] as $id) {
            self::assertStringContainsString("#$id ", $message);
        }
        self::assertStringNotContainsString("#$ok ", $message);
        self::assertStringContainsString('Conflit un', $message);
        // Aucun état partiel : pas de table projects, colonnes d'origine, migration non enregistrée.
        self::assertNull($this->pdo->query("SELECT to_regclass('public.projects')")->fetchColumn());
        $cols = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'tasks'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('category', $cols);
        self::assertContains('project', $cols);
        self::assertNotContains('project_id', $cols);
        self::assertSame(['0001_tasks_and_dependencies', '0002_task_status', '0003_task_project_conclusions'], (new Migrator($this->pdo, self::MIGRATIONS))->status()['applied']);
        self::assertSame(5, (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn());

        // Arbitrage manuel, puis relance.
        $this->pdo->exec("UPDATE tasks SET category = 'Pro' WHERE id = $c1");
        $this->pdo->exec("UPDATE tasks SET project = NULL WHERE id IN ($c2, $c3)");
        self::assertSame(['0004_projects'], $this->migrate());
        self::assertSame(5, (int) $this->pdo->query('SELECT count(*) FROM tasks WHERE project_id IS NOT NULL')->fetchColumn());
    }

    /**
     * Le rapport (temps 1) liste exactement ce que la garde (temps 2) refuse : même ensemble de tâches, même nombre,
     * et la liste tronquée de la migration (50 premières, par id) est le début de celle du rapport.
     */
    public function testReportListsExactlyWhatTheGuardRefuses(): void
    {
        $this->atVersion0003();
        $projects = ['MS-HR' => 'Pro', 'Jardin/Maison' => 'Perso', 'OPF' => 'Perso', 'Erendil' => 'Perso'];
        $expectedConflicts = [];
        for ($n = 0; $n < 120; $n++) {
            $project = array_keys($projects)[$n % 4];
            $category = $n % 3 === 0 ? ($projects[$project] === 'Pro' ? 'Perso' : 'Pro') : $projects[$project]; // 1 sur 3 : conflit
            $id = $this->oldTask("T$n", $category, $project);
            if ($category !== $projects[$project]) {
                $expectedConflicts[] = $id;
            }
        }
        for ($n = 0; $n < 5; $n++) { // sans projet : jamais un conflit
            $this->oldTask("Libre $n", $n % 2 === 0 ? 'Pro' : 'Perso');
        }
        self::assertCount(40, $expectedConflicts);

        $report = (new ProjectConflictReport($this->pdo))->build();
        self::assertSame($expectedConflicts, array_column($report['conflicts'], 'id'), 'le rapport = les conflits attendus');
        self::assertSame(['Pro' => 3, 'Perso' => 2], $report['without_project']);

        $message = $this->refusalMessage();
        self::assertStringContainsString('40 tâche(s)', $message);
        preg_match_all('/#(\d+) «/', $message, $m);
        $listed = array_map('intval', $m[1]);
        self::assertSame(array_slice($expectedConflicts, 0, 50), $listed, 'liste de la garde = les 50 premiers (ici tous) du rapport');
    }

    public function testGuardListIsTruncatedToFiftyButCountsAll(): void
    {
        $this->atVersion0003();
        $ids = [];
        for ($n = 0; $n < 55; $n++) {
            $ids[] = $this->oldTask("C$n", 'Perso', 'MS-HR'); // MS-HR est Pro : 55 conflits
        }
        $report = (new ProjectConflictReport($this->pdo))->build();
        self::assertSame($ids, array_column($report['conflicts'], 'id'));

        $message = $this->refusalMessage();
        self::assertStringContainsString('55 tâche(s)', $message);
        preg_match_all('/#(\d+) «/', $message, $m);
        self::assertSame(array_slice($ids, 0, 50), array_map('intval', $m[1]));
    }

    public function testNoConflictMeansEmptyReportAndSuccessfulMigration(): void
    {
        $this->atVersion0003();
        $this->oldTask('A', 'Pro', 'MS-HR');
        $this->oldTask('B', 'Perso', 'OPF');
        $this->oldTask('C', 'Perso');
        self::assertSame([], (new ProjectConflictReport($this->pdo))->build()['conflicts']);
        self::assertSame(['0004_projects'], $this->migrate());
    }

    // --- Rapport : lecture seule, garde de schéma, ligne de commande ---

    public function testReportIsReadOnlyAndLeavesNoTransactionOpen(): void
    {
        $this->atVersion0003();
        $this->oldTask('Conflit', 'Perso', 'MS-HR');
        $snapshot = fn (): array => [
            $this->pdo->query("SELECT md5(string_agg(t::text || t.xmin::text, ',' ORDER BY t.id)) FROM tasks t")->fetchColumn(),
            $this->pdo->query("SELECT string_agg(table_name, ',' ORDER BY table_name) FROM information_schema.tables WHERE table_schema = 'public'")->fetchColumn(),
            $this->pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn(),
        ];
        $before = $snapshot();

        (new ProjectConflictReport($this->pdo))->build();

        self::assertSame($before, $snapshot());
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('off', $this->pdo->query('SHOW transaction_read_only')->fetchColumn());
    }

    /** Les seules instructions de contrôle émises sont BEGIN READ ONLY puis ROLLBACK (jamais COMMIT), autour de lectures. */
    public function testReportUsesAReadOnlyTransactionRolledBack(): void
    {
        $this->atVersion0003();
        $this->oldTask('Conflit', 'Perso', 'MS-HR');
        $pdo = new class (sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->config->dbHost, $this->config->dbPort, $this->config->dbName), $this->config->dbUser, $this->config->dbPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]) extends \PDO {
            /** @var list<string> */
            public array $executed = [];

            public function exec(string $statement): int|false
            {
                $this->executed[] = $statement;

                return parent::exec($statement);
            }
        };
        (new ProjectConflictReport($pdo))->build();
        self::assertSame(['BEGIN READ ONLY', 'ROLLBACK'], $pdo->executed);
    }

    public function testReportRefusesWhenProjectsTableAlreadyExists(): void
    {
        $this->migrate();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('0004 est appliquée');
        (new ProjectConflictReport($this->pdo))->build();
    }

    public function testReportRefusesWithoutMigration0003(): void
    {
        $this->migrateTo('0002_task_status');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('0003');
        (new ProjectConflictReport($this->pdo))->build();
    }

    /** @return array{0:int,1:string,2:string} code de sortie, stdout, stderr */
    private function runReport(array $env): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/report-project-conflicts'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + (getenv() ?: []) + ['DB_NAME' => $this->config->dbName],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testReportCommandExitCodes(): void
    {
        $this->atVersion0003();
        $c = $this->oldTask('Titre conflictuel', 'Perso', 'MS-HR', 'in_progress');
        $this->oldTask('Libre', 'Pro');
        $env = ['APP_ENV' => 'test', 'DB_NAME' => $this->config->dbName];

        [$code, $out] = $this->runReport($env);
        self::assertSame(1, $code);
        self::assertStringContainsString("#$c [in_progress] « Titre conflictuel » : catégorie Perso, projet MS-HR (catégorie attendue Pro)", $out);
        self::assertStringContainsString('Divers Pro = 1, Divers Perso = 0', $out);

        $this->pdo->exec("UPDATE tasks SET category = 'Pro' WHERE id = $c");
        [$code, $out] = $this->runReport($env);
        self::assertSame(0, $code);
        self::assertStringContainsString('Aucun conflit', $out);

        [$code, , $err] = $this->runReport(['APP_ENV' => 'production'] + $env);
        self::assertSame(1, $code, 'refus hors local/test');
        self::assertStringContainsString('Refus', $err);

        $this->migrate();
        [$code, , $err] = $this->runReport($env);
        self::assertSame(1, $code, 'refus si 0004 est déjà appliquée');
        self::assertStringContainsString('0004 est appliquée', $err);
    }
}
