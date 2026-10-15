<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\DatabaseUnavailable;
use Actions\Application\InvalidProject;
use Actions\Application\InvalidTask;
use Actions\Application\ProjectInUse;
use Actions\Application\ProjectNameTaken;
use Actions\Application\ProjectNotFound;
use Actions\Application\ProjectService;
use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Tests\Support\Services;

/** ProjectService et son effet sur les tâches (catégorie dérivée), contre PostgreSQL réel (actions_test). */
final class ProjectServiceTest extends DbTestCase
{
    private ProjectService $projects;
    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->projects = Bootstrap::projectService($this->config);
        $this->tasks = Bootstrap::taskService($this->config);
    }

    private function idOf(string $name): int
    {
        return $this->projectId($name);
    }

    private function backdate(int $taskId): string
    {
        $this->pdo->exec("UPDATE tasks SET updated_at = '2000-01-01 00:00:00+00' WHERE id = $taskId");

        return (string) $this->pdo->query("SELECT updated_at::text FROM tasks WHERE id = $taskId")->fetchColumn();
    }

    private function updatedAt(int $taskId): string
    {
        return (string) $this->pdo->query("SELECT updated_at::text FROM tasks WHERE id = $taskId")->fetchColumn();
    }

    private function refused(string $class, string $code, callable $fn): \Actions\Application\DomainError
    {
        try {
            $fn();
        } catch (\Actions\Application\DomainError $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($code, $e->code());

            return $e;
        }
        self::fail("$class($code) attendue.");
    }

    // --- Lecture ---

    public function testListGivesTheSixProjectsSortedWithTheirShape(): void
    {
        $list = $this->projects->list();
        self::assertSame(
            ['Divers Perso', 'Divers Pro', 'Erendil', 'Jardin/Maison', 'MS-HR', 'OPF'],
            array_column($list, 'name'),
        );
        self::assertSame(['id', 'name', 'category', 'task_count'], array_keys($list[0]));
        self::assertSame(['Perso', 'Pro', 'Perso', 'Perso', 'Pro', 'Perso'], array_column($list, 'category'));
        self::assertSame([0, 0, 0, 0, 0, 0], array_column($list, 'task_count'));
        self::assertSame(array_column($list, 'name'), $this->projects->names());
        $this->tasks->create(['title' => 'a', 'project' => 'OPF']);
        $this->tasks->create(['title' => 'b', 'project' => 'OPF']);
        $opf = $this->projects->get($this->idOf('OPF'));
        self::assertSame(2, $opf['task_count']);
        self::assertSame($opf, array_values(array_filter($this->projects->list(), static fn (array $p): bool => $p['name'] === 'OPF'))[0]);
    }

    public function testListIsSortedIgnoringCase(): void
    {
        $this->projects->create(['name' => 'alpha', 'category' => 'Pro']);
        $this->projects->create(['name' => 'Zeta', 'category' => 'Pro']);
        $this->projects->create(['name' => 'Beta', 'category' => 'Perso']);
        self::assertSame(['alpha', 'Beta', 'Divers Perso', 'Divers Pro', 'Erendil', 'Jardin/Maison', 'MS-HR', 'OPF', 'Zeta'], $this->projects->names());
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $e = $this->refused(ProjectNotFound::class, 'project_not_found', fn () => $this->projects->get(999999));
        self::assertSame(['id' => 999999], $e->details());
    }

    // --- Création ---

    public function testCreateKeepsTheNameAsIsAndStartsEmpty(): void
    {
        $p = $this->projects->create(['name' => '  Mon projet é/ü  ', 'category' => 'Pro']);
        self::assertSame(['id', 'name', 'category', 'task_count'], array_keys($p));
        self::assertSame(['  Mon projet é/ü  ', 'Pro', 0], [$p['name'], $p['category'], $p['task_count']]);
        self::assertSame($p, $this->projects->get($p['id']));
        // Utilisable aussitôt par les tâches.
        $t = $this->tasks->create(['title' => 'x', 'project' => '  Mon projet é/ü  ']);
        self::assertSame('Pro', $t['category']);
    }

    public function testCreateRefusesDuplicatesIgnoringCaseAndCreatesNothing(): void
    {
        foreach (['OPF', 'opf', 'Opf', 'ms-hr', 'DIVERS pro'] as $name) {
            $e = $this->refused(ProjectNameTaken::class, 'project_name_taken', fn () => $this->projects->create(['name' => $name, 'category' => 'Pro']));
            self::assertSame(['field' => 'name'], $e->details());
        }
        self::assertCount(6, $this->projects->list());
    }

    public function testCreateValidationRefusalsCreateNothing(): void
    {
        foreach ([
            [['name' => '', 'category' => 'Pro'], 'project_name_blank'],
            [['name' => '  ', 'category' => 'Pro'], 'project_name_blank'],
            [['name' => str_repeat('a', 256), 'category' => 'Pro'], 'project_name_too_long'],
            [['name' => "a\0b", 'category' => 'Pro'], 'text_invalid'],
            [['name' => 'X', 'category' => 'pro'], 'category_invalid'],
            [['name' => 'X'], 'category_invalid'],
            [['category' => 'Pro'], 'project_name_blank'],
            [['name' => 'X', 'category' => 'Pro', 'id' => 7], 'field_unknown'],
            [['name' => 5, 'category' => 'Pro'], 'field_type_invalid'],
        ] as [$data, $code]) {
            $this->refused(InvalidProject::class, $code, fn () => $this->projects->create($data));
        }
        self::assertCount(6, $this->projects->list());
    }

    // --- Modification ---

    public function testRenameIsFreeAndTasksFollow(): void
    {
        $t = $this->tasks->create(['title' => 'a', 'project' => 'OPF']);
        $id = $this->idOf('OPF');
        $r = $this->projects->update($id, ['name' => 'opf']); // même nom, casse différente : permis
        self::assertSame('opf', $r['project']['name']);
        self::assertSame(0, $r['tasks_recategorized']);
        self::assertSame('opf', $this->tasks->get($t['id'])['project'], 'rattachement interne : la tâche suit');
        $r = $this->projects->update($id, ['name' => 'Mon OPF']);
        self::assertSame(['Mon OPF', 1], [$r['project']['name'], $r['project']['task_count']]);
        self::assertSame('Mon OPF', $this->tasks->get($t['id'])['project']);
        // Casse exacte (PC14) : l'ancien nom ne désigne plus rien.
        $this->refused(InvalidTask::class, 'project_invalid', fn () => $this->tasks->create(['title' => 'b', 'project' => 'OPF']));
        $this->tasks->create(['title' => 'b', 'project' => 'Mon OPF']);
        $this->refused(InvalidTask::class, 'project_invalid', fn () => $this->tasks->create(['title' => 'c', 'project' => 'mon opf']));
    }

    public function testRenameToAnotherNameIsRefusedIgnoringCase(): void
    {
        $id = $this->idOf('OPF');
        foreach (['Erendil', 'erendil', 'MS-HR'] as $name) {
            $this->refused(ProjectNameTaken::class, 'project_name_taken', fn () => $this->projects->update($id, ['name' => $name]));
        }
        self::assertSame('OPF', $this->projects->get($id)['name']);
    }

    public function testCategoryChangeFollowsOnAllTasksIncludingDoneWithoutRewritingThem(): void
    {
        $a = $this->tasks->create(['title' => 'a', 'project' => 'OPF']);
        $b = $this->tasks->create(['title' => 'b', 'project' => 'OPF']);
        $this->tasks->create(['title' => 'autre projet', 'project' => 'Erendil']);
        $this->tasks->changeStatus($b['id'], 'in_progress');
        $this->tasks->changeStatus($b['id'], 'done');
        $stampA = $this->backdate($a['id']);
        $stampB = $this->backdate($b['id']);
        $id = $this->idOf('OPF');

        $r = $this->projects->update($id, ['category' => 'Pro']);

        self::assertSame(2, $r['tasks_recategorized'], 'tâches terminées comprises');
        self::assertSame(['Pro', 2], [$r['project']['category'], $r['project']['task_count']]);
        self::assertSame('Pro', $this->tasks->get($a['id'])['category']);
        self::assertSame('Pro', $this->tasks->get($b['id'])['category']);
        self::assertSame('Perso', $this->tasks->list(['project' => 'Erendil'])[0]['category'], 'les autres projets ne bougent pas');
        self::assertSame([$stampA, $stampB], [$this->updatedAt($a['id']), $this->updatedAt($b['id'])], 'H2 : updated_at des tâches inchangé');
        self::assertSame([$a['id'], $b['id']], array_column($this->tasks->list(['category' => 'Pro', 'project' => 'OPF']), 'id'), 'filtre par catégorie dérivée');
        self::assertSame([], $this->tasks->list(['category' => 'Perso', 'project' => 'OPF']));
    }

    public function testUnchangedCategoryAndEmptyChangesWriteNothing(): void
    {
        $t = $this->tasks->create(['title' => 'a', 'project' => 'OPF']);
        $id = $this->idOf('OPF');
        self::assertSame(0, $this->projects->update($id, ['category' => 'Perso'])['tasks_recategorized'], 'catégorie inchangée : 0');
        self::assertSame(0, $this->projects->update($id, [])['tasks_recategorized']);
        self::assertSame(0, $this->projects->update($id, ['name' => 'OPF', 'category' => 'Perso'])['tasks_recategorized']);
        self::assertSame('OPF', $this->projects->get($id)['name']);
        self::assertSame('Perso', $this->tasks->get($t['id'])['category']);
    }

    public function testUpdateRefusalsLeaveTheProjectUntouched(): void
    {
        $id = $this->idOf('OPF');
        $this->refused(ProjectNotFound::class, 'project_not_found', fn () => $this->projects->update(999999, ['name' => '']));
        foreach ([
            [['name' => null], 'field_type_invalid'], [['category' => null], 'field_type_invalid'],
            [['name' => ''], 'project_name_blank'], [['category' => 'Autre'], 'category_invalid'],
            [['name' => str_repeat('a', 256)], 'project_name_too_long'], [['task_count' => 3], 'field_unknown'],
        ] as [$changes, $code]) {
            $this->refused(InvalidProject::class, $code, fn () => $this->projects->update($id, $changes));
        }
        self::assertSame(['OPF', 'Perso'], [$this->projects->get($id)['name'], $this->projects->get($id)['category']]);
    }

    // --- Suppression ---

    public function testDeleteIsRefusedWhileTasksExistThenAllowed(): void
    {
        $t = $this->tasks->create(['title' => 'a', 'project' => 'OPF']);
        $id = $this->idOf('OPF');
        $e = $this->refused(ProjectInUse::class, 'project_in_use', fn () => $this->projects->delete($id));
        self::assertSame(['id' => $id, 'task_count' => 1], $e->details());
        self::assertSame($id, $this->projects->get($id)['id']);
        // Un projet utilisé par une tâche terminée est aussi protégé.
        $this->tasks->changeStatus($t['id'], 'in_progress');
        $this->tasks->changeStatus($t['id'], 'done');
        $this->refused(ProjectInUse::class, 'project_in_use', fn () => $this->projects->delete($id));
        $this->tasks->delete($t['id']);
        $this->projects->delete($id);
        $this->refused(ProjectNotFound::class, 'project_not_found', fn () => $this->projects->get($id));
        $this->refused(ProjectNotFound::class, 'project_not_found', fn () => $this->projects->delete($id));
        $this->refused(InvalidTask::class, 'project_invalid', fn () => $this->tasks->create(['title' => 'b', 'project' => 'OPF']));
        self::assertCount(5, $this->projects->list());
    }

    public function testHomeProjectsAreOrdinaryAndDeletableWhenEmpty(): void
    {
        $this->projects->delete($this->idOf('Divers Pro'));
        $this->projects->delete($this->idOf('Divers Perso'));
        self::assertSame(['Erendil', 'Jardin/Maison', 'MS-HR', 'OPF'], $this->projects->names());
    }

    // --- Effets sur les tâches ---

    public function testChangingTheProjectOfATaskIsAllowedAtAnyStateAndCategoryFollows(): void
    {
        $t = $this->tasks->create(['title' => 'a', 'project' => 'MS-HR']);
        $this->tasks->changeStatus($t['id'], 'in_progress');
        $this->tasks->changeStatus($t['id'], 'done');
        $moved = $this->tasks->update($t['id'], ['project' => 'Erendil']);
        self::assertSame(['done', 'Erendil', 'Perso'], [$moved['status'], $moved['project'], $moved['category']]);
        $this->refused(InvalidTask::class, 'project_invalid', fn () => $this->tasks->update($t['id'], ['project' => null]));
        self::assertSame('Erendil', $this->tasks->get($t['id'])['project']);
    }

    public function testDependenciesBetweenProAndPersoTasksAreAllowed(): void
    {
        $pro = $this->tasks->create(['title' => 'pro', 'project' => 'MS-HR']);
        $perso = $this->tasks->create(['title' => 'perso', 'project' => 'OPF']);
        $linked = $this->tasks->addRequiredAction($pro['id'], $perso['id']);
        self::assertSame([$perso['id']], array_column($linked['required_actions'], 'id'));
    }

    public function testRecurringCompletionCopiesTheProjectAndFollowsLaterChanges(): void
    {
        $t = $this->tasks->create(['title' => 'p', 'project' => 'OPF', 'conclusions' => 'c', 'estimated_minutes' => 20, 'description' => 'd', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $this->projects->update($this->idOf('OPF'), ['category' => 'Pro', 'name' => 'OPF renommé']);
        $this->tasks->changeStatus($t['id'], 'in_progress');
        $next = $this->tasks->changeStatus($t['id'], 'done')['next_occurrence'];
        self::assertSame(['OPF renommé', 'Pro', null, 20, 'd', 'todo', []], [$next['project'], $next['category'], $next['conclusions'], $next['estimated_minutes'], $next['description'], $next['status'], $next['required_actions']]);
        self::assertSame(['p', '2026-02-28', ['interval' => 1, 'unit' => 'month']], [$next['title'], $next['due_date'], $next['recurrence']]);
    }

    public function testTaskDeletedProjectBetweenResolutionAndWriteIsProjectInvalid(): void
    {
        // La base arbitre : écrire un projet disparu (course) donne project_invalid, tâche non écrite.
        $repo = new TaskRepository(fn (): \PDO => $this->pdo);
        $task = $this->tasks->create(['title' => 'a', 'project' => 'OPF']);
        $data = ['title' => 'x', 'due_date' => null, 'recurrence' => null, 'estimated_minutes' => null, 'description' => null, 'conclusions' => null];
        $this->refused(InvalidTask::class, 'project_invalid', fn () => $repo->insert($data, 999999));
        $this->refused(InvalidTask::class, 'project_invalid', fn () => $repo->update($task['id'], ['project_id' => 999999]));
        self::assertSame('OPF', $this->tasks->get($task['id'])['project']);
        self::assertCount(1, $this->tasks->list());
    }

    public function testLockingATaskDoesNotLockItsProjectRow(): void
    {
        // find(..., forUpdate) = FOR UPDATE OF t : le renommage du projet par une autre connexion n'attend pas.
        $task = $this->tasks->create(['title' => 'a', 'project' => 'OPF']);
        $other = \Actions\Infrastructure\Database::connect($this->config);
        $other->exec("SET lock_timeout = '500ms'");
        $repo = new TaskRepository(\Closure::fromCallable(fn (): \PDO => $this->pdo));
        $repo->transaction(function () use ($repo, $task, $other): void {
            self::assertNotNull($repo->find($task['id'], true));
            $other->exec("UPDATE projects SET name = 'OPF 2' WHERE name = 'OPF'"); // 55P03 si la ligne projet était verrouillée
            // En revanche, une autre connexion ne peut pas verrouiller la même tâche.
            try {
                $other->query('SELECT id FROM tasks WHERE id = ' . $task['id'] . ' FOR UPDATE')->fetchAll();
                self::fail('Le verrou de la tâche devrait bloquer.');
            } catch (\PDOException $e) {
                self::assertSame('55P03', $e->errorInfo[0]);
            }
        });
        self::assertSame('OPF 2', $this->tasks->get($task['id'])['project']);
    }

    public function testUnreachableDatabaseIsDatabaseUnavailable(): void
    {
        $svc = Services::projects(static fn (): \PDO => throw new \PDOException('connexion refusée'));
        $this->expectException(DatabaseUnavailable::class);
        $svc->list();
    }
}
