<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\InvalidTask;
use Actions\Application\TaskService;
use Actions\Application\TaskValidator;
use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Migrator;
use Actions\Tests\Support\ArraySession;

/**
 * Importance d'une action (Mineure, Majeure, Critique) : migration 0005, couche commune, REST, MCP et portail
 * contre PostgreSQL réel (base actions_test, remigrée dans setUp).
 */
final class TaskImportanceTest extends DbTestCase
{
    private const HOST = '127.0.0.1:8080';
    private const VALUES = ['Mineure', 'Majeure', 'Critique'];
    private const BAD = ['', 'mineure', 'MAJEURE', 'critique', 'Critique ', ' Mineure', 'Autre', 'Majeur'];

    private Kernel $kernel;
    private ArraySession $session;
    private TaskService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->session = new ArraySession();
        $this->kernel = Bootstrap::kernel($this->config, $this->session);
        $this->svc = Bootstrap::taskService($this->config);
    }

    // --- Aides ---

    /** @param array<string,mixed> $query */
    private function rest(string $method, string $path, mixed $body = null, array $query = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, [], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), $query));
    }

    /** @return array<string,mixed> */
    private function json(Response $r): array
    {
        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function make(array $data = []): array
    {
        return $this->svc->create($data + ['title' => 'T', 'project' => 'Divers Pro']);
    }

    /** @return array<string,mixed> */
    private function rpc(string $name, array $arguments): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]], JSON_THROW_ON_ERROR);
        $r = $this->kernel->handle(new Request('POST', '/mcp', ['host' => self::HOST, 'content-type' => 'application/json'], $body));
        self::assertSame(200, $r->status, $r->body);

        return $this->json($r);
    }

    private function mcpText(string $name, array $arguments, bool $isError): string
    {
        $rpc = $this->rpc($name, $arguments);
        self::assertSame($isError, $rpc['result']['isError'], json_encode($rpc));

        return $rpc['result']['content'][0]['text'];
    }

    /** @param array<string,mixed> $form */
    private function portalPost(string $path, array $form): Response
    {
        $form += ['_csrf' => $this->session->csrfToken()];

        return $this->kernel->handle(new Request('POST', $path, ['host' => self::HOST], '', [], $form));
    }

    /** @param array<string,mixed> $query */
    private function portalGet(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, ['host' => self::HOST], '', $query));
    }

    private function backdate(int $id): void
    {
        $this->pdo->exec("UPDATE tasks SET updated_at = '2000-01-01 00:00:00+00' WHERE id = $id");
    }

    private function updatedAt(int $id): string
    {
        return (string) $this->pdo->query("SELECT updated_at::text FROM tasks WHERE id = $id")->fetchColumn();
    }

    private function rawImportance(int $id): string
    {
        return (string) $this->pdo->query("SELECT importance FROM tasks WHERE id = $id")->fetchColumn();
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn();
    }

    private function assertInvalid(callable $fn, string $code, string $field = 'importance'): InvalidTask
    {
        try {
            $fn();
        } catch (InvalidTask $e) {
            self::assertSame($code, $e->code());
            self::assertSame($field, $e->details()['field']);

            return $e;
        }
        self::fail("$code attendu");
    }

    // --- Migration 0005 ---

    public function testMigration0005IsApplied(): void
    {
        self::assertContains('0005_task_importance', (new Migrator($this->pdo, self::MIGRATIONS))->status()['applied']);
        self::assertSame([], (new Migrator($this->pdo, self::MIGRATIONS))->migrate(), 'rejeu : rien à appliquer');
    }

    public function testMigration0005KeepsExistingTasksAsMineureWithoutTouchingThem(): void
    {
        $this->dropSchema();
        self::assertNotSame([], $this->migrateTo('0004_projects'));
        $pid = $this->projectId('Divers Pro');
        $this->pdo->exec("INSERT INTO tasks (title, project_id, due_date, description) VALUES ('A', $pid, '2026-01-31', 'ctx'), ('B', $pid, NULL, NULL)");
        $this->pdo->exec("UPDATE tasks SET status = 'in_progress' WHERE id = 1");
        $this->pdo->exec("UPDATE tasks SET status = 'in_progress' WHERE id = 2");
        $this->pdo->exec("UPDATE tasks SET status = 'done', completed_at = now() WHERE id = 2");
        $this->pdo->exec('INSERT INTO task_dependencies (task_id, required_task_id) VALUES (1, 2)');
        $cols = 'id, title, project_id, due_date, description, status, completed_at, created_at, updated_at';
        $before = $this->pdo->query("SELECT $cols FROM tasks ORDER BY id")->fetchAll();

        self::assertSame(['0005_task_importance'], (new Migrator($this->pdo, self::MIGRATIONS))->migrate());

        self::assertSame($before, $this->pdo->query("SELECT $cols FROM tasks ORDER BY id")->fetchAll(), 'colonnes existantes et updated_at inchangés');
        self::assertSame(['Mineure', 'Mineure'], $this->pdo->query('SELECT importance FROM tasks ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM task_dependencies')->fetchColumn());
        self::assertSame([], (new Migrator($this->pdo, self::MIGRATIONS))->migrate());
    }

    public function testDatabaseAcceptsTheThreeValuesAndRefusesTheRest(): void
    {
        $pid = $this->projectId('Divers Pro');
        foreach (self::VALUES as $v) {
            $this->pdo->prepare('INSERT INTO tasks (title, project_id, importance) VALUES (?, ?, ?)')->execute(['t', $pid, $v]);
        }
        foreach (['', 'mineure', 'MAJEURE', 'Autre', 'Critique '] as $bad) {
            $this->assertCheck('23514', fn () => $this->pdo->prepare('INSERT INTO tasks (title, project_id, importance) VALUES (?, ?, ?)')->execute(['t', $pid, $bad]));
        }
        $this->assertCheck('23502', fn () => $this->pdo->exec("INSERT INTO tasks (title, project_id, importance) VALUES ('t', $pid, NULL)"));
        $this->assertCheck('23514', fn () => $this->pdo->exec("UPDATE tasks SET importance = 'critique' WHERE id = 1"));
        self::assertSame(3, $this->rows());
    }

    private function assertCheck(string $sqlstate, callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            self::assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());
            if ($sqlstate === '23514') {
                self::assertStringContainsString('tasks_importance_valid', $e->getMessage());
            }

            return;
        }
        self::fail("Violation $sqlstate attendue.");
    }

    public function testValidatorConstantsMatchTheSchema(): void
    {
        self::assertSame(self::VALUES, TaskValidator::IMPORTANCES);
        foreach (TaskValidator::IMPORTANCES as $v) {
            $this->pdo->prepare("INSERT INTO tasks (title, project_id, importance) VALUES ('t', ?, ?)")->execute([$this->projectId('Divers Pro'), $v]);
        }
        $default = (string) $this->pdo->query("SELECT column_default FROM information_schema.columns WHERE table_name = 'tasks' AND column_name = 'importance'")->fetchColumn();
        self::assertSame("'" . TaskValidator::DEFAULT_IMPORTANCE . "'::text", $default);
        self::assertSame('Mineure', TaskValidator::DEFAULT_IMPORTANCE);
        self::assertSame('NO', $this->pdo->query("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'tasks' AND column_name = 'importance'")->fetchColumn());
    }

    // --- Service ---

    public function testCreationDefaultsToMineureAndKeepsGivenValue(): void
    {
        $t = $this->make();
        self::assertSame('Mineure', $t['importance']);
        self::assertSame('Mineure', $this->rawImportance($t['id']));
        foreach (['Majeure', 'Critique', 'Mineure'] as $v) {
            $t = $this->make(['importance' => $v]);
            self::assertSame($v, $t['importance']);
            self::assertSame($t, $this->svc->get($t['id']));
        }
    }

    public function testCreationRefusesInvalidNullAndWrongTypes(): void
    {
        $rows = $this->rows();
        foreach (array_merge(self::BAD, [null]) as $bad) {
            $this->assertInvalid(fn () => $this->make(['importance' => $bad]), 'importance_invalid');
        }
        foreach ([1, true, ['Majeure'], 1.5] as $bad) {
            $this->assertInvalid(fn () => $this->make(['importance' => $bad]), 'field_type_invalid');
        }
        self::assertSame($rows, $this->rows());
        self::assertSame(
            "L'importance doit être Mineure, Majeure ou Critique.",
            $this->assertInvalid(fn () => $this->make(['importance' => 'x']), 'importance_invalid')->getMessage(),
        );
    }

    public function testUpdateSetsChangesAndLeavesUntouchedWhenAbsent(): void
    {
        $id = $this->make()['id'];
        self::assertSame('Critique', $this->svc->update($id, ['importance' => 'Critique'])['importance']);
        self::assertSame('Majeure', $this->svc->update($id, ['importance' => 'Majeure'])['importance']);
        $t = $this->svc->update($id, ['title' => 'Autre']);
        self::assertSame('Majeure', $t['importance'], 'clé absente : inchangée');
        self::assertSame('Majeure', $this->rawImportance($id));
    }

    public function testUpdateRefusalsLeaveTheTaskUnchanged(): void
    {
        $id = $this->make(['importance' => 'Critique'])['id'];
        $this->backdate($id);
        $before = $this->pdo->query("SELECT * FROM tasks WHERE id = $id")->fetch();
        foreach (array_merge(self::BAD, [null]) as $bad) {
            $this->assertInvalid(fn () => $this->svc->update($id, ['importance' => $bad]), 'importance_invalid');
        }
        foreach ([1, false, ['x']] as $bad) {
            $this->assertInvalid(fn () => $this->svc->update($id, ['importance' => $bad]), 'field_type_invalid');
        }
        self::assertSame($before, $this->pdo->query("SELECT * FROM tasks WHERE id = $id")->fetch());
    }

    public function testIdenticalUpdateDoesNotWriteButAChangeDoes(): void
    {
        $id = $this->make(['importance' => 'Majeure'])['id'];
        $this->backdate($id);
        $before = $this->updatedAt($id);
        $this->svc->update($id, ['importance' => 'Majeure']);
        self::assertSame($before, $this->updatedAt($id));
        $this->svc->update($id, ['importance' => 'Critique']);
        self::assertNotSame($before, $this->updatedAt($id));
    }

    public function testImportanceIsEditableOnADoneTaskAndReopeningKeepsIt(): void
    {
        $id = $this->make(['importance' => 'Mineure'])['id'];
        $this->svc->changeStatus($id, 'in_progress');
        $this->svc->changeStatus($id, 'done');
        $this->backdate($id);
        $t = $this->svc->update($id, ['importance' => 'Critique']);
        self::assertSame(['done', 'Critique'], [$t['status'], $t['importance']]);
        self::assertNotSame('2000-01-01 00:00:00+00', $this->updatedAt($id));
        self::assertSame('Critique', $this->svc->changeStatus($id, 'todo')['task']['importance'], 'la réouverture ne touche pas à l\'importance');
    }

    public function testStatusWithImportanceIsStatusNotAlone(): void
    {
        $id = $this->make()['id'];
        $this->assertInvalid(fn () => $this->svc->patch($id, ['status' => 'in_progress', 'importance' => 'Critique']), 'status_not_alone', 'status');
        self::assertSame(['todo', 'Mineure'], [$this->svc->get($id)['status'], $this->svc->get($id)['importance']]);
    }

    public function testNoCrossRuleWithDependencies(): void
    {
        $minor = $this->make(['title' => 'm', 'importance' => 'Mineure'])['id'];
        $crit = $this->make(['title' => 'c', 'importance' => 'Critique'])['id'];
        self::assertSame($minor, $this->svc->addRequiredAction($crit, $minor)['required_actions'][0]['id'], 'Critique requiert Mineure');
        $a = $this->make(['title' => 'a', 'importance' => 'Mineure'])['id'];
        $b = $this->make(['title' => 'b', 'importance' => 'Critique'])['id'];
        self::assertSame($b, $this->svc->addRequiredAction($a, $b)['required_actions'][0]['id'], 'Mineure requiert Critique');
    }

    public function testFilterAloneAndAndedWithOtherFilters(): void
    {
        $a = $this->make(['title' => 'a', 'importance' => 'Critique', 'project' => 'MS-HR', 'due_date' => '2026-03-01'])['id'];
        $b = $this->make(['title' => 'b', 'importance' => 'Critique', 'project' => 'OPF', 'due_date' => '2026-01-01'])['id'];
        $c = $this->make(['title' => 'c', 'importance' => 'Majeure', 'project' => 'OPF'])['id'];
        $d = $this->make(['title' => 'd', 'project' => 'OPF'])['id'];
        $ids = fn (array $f): array => array_column($this->svc->list($f), 'id');
        self::assertSame([$b, $a], $ids(['importance' => 'Critique']), 'tri inchangé : date butée puis id, importance sans effet');
        self::assertSame([$c], $ids(['importance' => 'Majeure']));
        self::assertSame([$d], $ids(['importance' => 'Mineure']));
        self::assertSame([$b], $ids(['importance' => 'Critique', 'project' => 'OPF']));
        self::assertSame([$a], $ids(['importance' => 'Critique', 'category' => 'Pro']));
        self::assertSame([], $ids(['importance' => 'Majeure', 'category' => 'Pro']));
        $this->svc->changeStatus($b, 'in_progress');
        self::assertSame([$b], $ids(['importance' => 'Critique', 'status' => 'in_progress']));
        self::assertSame([$a], $ids(['importance' => 'Critique', 'status' => 'todo']));
        foreach (self::BAD as $bad) {
            $this->assertInvalid(fn () => $this->svc->list(['importance' => $bad]), 'importance_invalid');
        }
        foreach ([1, ['Critique'], null, true] as $bad) {
            $this->assertInvalid(fn () => $this->svc->list(['importance' => $bad]), 'field_type_invalid');
        }
    }

    public function testCompletingARecurringTaskCopiesImportance(): void
    {
        foreach (self::VALUES as $v) {
            $id = $this->make(['title' => $v, 'importance' => $v, 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']])['id'];
            $this->svc->changeStatus($id, 'in_progress');
            $r = $this->svc->changeStatus($id, 'done');
            self::assertSame($v, $r['task']['importance'], 'l\'occurrence terminée garde la sienne');
            self::assertSame($v, $r['next_occurrence']['importance'], 'la suivante reprend l\'importance');
            self::assertSame($r['next_occurrence'], $this->svc->get($r['next_occurrence']['id']));
            // Réouverture : importance conservée.
            self::assertSame($v, $this->svc->changeStatus($id, 'todo')['task']['importance']);
        }
    }

    public function testChangingImportanceAfterCompletionDoesNotAffectTheNextOccurrence(): void
    {
        $id = $this->make(['importance' => 'Majeure', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']])['id'];
        $this->svc->changeStatus($id, 'in_progress');
        $next = $this->svc->changeStatus($id, 'done')['next_occurrence']['id'];
        $this->svc->update($id, ['importance' => 'Critique']);
        self::assertSame('Majeure', $this->svc->get($next)['importance']);
    }

    public function testCompletingANonRecurringTaskCreatesNothing(): void
    {
        $id = $this->make(['importance' => 'Critique'])['id'];
        $this->svc->changeStatus($id, 'in_progress');
        self::assertNull($this->svc->changeStatus($id, 'done')['next_occurrence']);
        self::assertSame(1, $this->rows());
    }

    // --- REST ---

    public function testRestPostWithAndWithoutImportance(): void
    {
        $r = $this->rest('POST', '/api/v1/tasks', ['title' => 'T', 'project' => 'Divers Pro']);
        self::assertSame(201, $r->status);
        self::assertSame('Mineure', $this->json($r)['importance']);
        $r = $this->rest('POST', '/api/v1/tasks', ['title' => 'T', 'project' => 'Divers Pro', 'importance' => 'Critique']);
        self::assertSame(201, $r->status);
        $created = $this->json($r);
        self::assertSame('Critique', $created['importance']);
        self::assertSame($created, $this->json($this->rest('GET', '/api/v1/tasks/' . $created['id'])));
        self::assertSame(['Mineure', 'Critique'], array_column($this->json($this->rest('GET', '/api/v1/tasks')), 'importance'));
    }

    public function testRestRefusals(): void
    {
        $rows = $this->rows();
        foreach (array_merge(self::BAD, [null]) as $bad) {
            $r = $this->rest('POST', '/api/v1/tasks', ['title' => 'T', 'project' => 'Divers Pro', 'importance' => $bad]);
            self::assertSame(422, $r->status, $r->body);
            self::assertSame(['importance_invalid', 'importance'], [$this->json($r)['error']['code'], $this->json($r)['error']['details']['field']]);
        }
        $r = $this->rest('POST', '/api/v1/tasks', ['title' => 'T', 'project' => 'Divers Pro', 'importance' => 3]);
        self::assertSame(422, $r->status);
        self::assertSame('field_type_invalid', $this->json($r)['error']['code']);
        self::assertSame($rows, $this->rows());

        $id = $this->make(['importance' => 'Majeure'])['id'];
        foreach (array_merge(self::BAD, [null]) as $bad) {
            $r = $this->rest('PATCH', '/api/v1/tasks/' . $id, ['importance' => $bad]);
            self::assertSame(422, $r->status, $r->body);
            self::assertSame('importance_invalid', $this->json($r)['error']['code']);
        }
        self::assertSame('Majeure', $this->rawImportance($id));
    }

    public function testRestPatchChangesAndKeepsImportance(): void
    {
        $id = $this->make()['id'];
        $r = $this->rest('PATCH', '/api/v1/tasks/' . $id, ['importance' => 'Critique']);
        self::assertSame(200, $r->status);
        self::assertSame('Critique', $this->json($r)['importance']);
        self::assertSame('Critique', $this->json($this->rest('PATCH', '/api/v1/tasks/' . $id, ['title' => 'Autre']))['importance']);
        $r = $this->rest('PATCH', '/api/v1/tasks/' . $id, ['status' => 'in_progress', 'importance' => 'Majeure']);
        self::assertSame('status_not_alone', $this->json($r)['error']['code']);
    }

    public function testRestFilter(): void
    {
        $a = $this->make(['title' => 'a', 'importance' => 'Critique'])['id'];
        $this->make(['title' => 'b', 'importance' => 'Majeure']);
        $c = $this->make(['title' => 'c', 'importance' => 'Critique', 'project' => 'OPF'])['id'];
        $ids = fn (array $q): array => array_column($this->json($this->rest('GET', '/api/v1/tasks', null, $q)), 'id');
        self::assertSame([$a, $c], $ids(['importance' => 'Critique']));
        self::assertSame([$c], $ids(['importance' => 'Critique', 'category' => 'Perso']));
        self::assertSame([$c], $ids(['importance' => 'Critique', 'project' => 'OPF']));
        self::assertSame([], $ids(['importance' => 'Mineure']));
        self::assertCount(3, $ids([]), 'absent : pas de filtre');
        self::assertCount(3, $ids(['inconnu' => 'x']), 'paramètre inconnu ignoré');
        foreach (['', 'critique', 'Autre'] as $bad) {
            $r = $this->rest('GET', '/api/v1/tasks', null, ['importance' => $bad]);
            self::assertSame(422, $r->status, $r->body);
            self::assertSame(['importance_invalid', 'importance'], [$this->json($r)['error']['code'], $this->json($r)['error']['details']['field']]);
        }
        $r = $this->rest('GET', '/api/v1/tasks', null, ['importance' => ['Critique']]);
        self::assertSame(422, $r->status);
        self::assertSame('field_type_invalid', $this->json($r)['error']['code']);
    }

    public function testRestCompletionCopiesImportance(): void
    {
        $id = $this->make(['importance' => 'Critique', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']])['id'];
        $this->rest('PATCH', '/api/v1/tasks/' . $id, ['status' => 'in_progress']);
        $body = $this->json($this->rest('PATCH', '/api/v1/tasks/' . $id, ['status' => 'done']));
        self::assertSame('Critique', $body['importance']);
        self::assertSame('Critique', $body['next_occurrence']['importance']);
    }

    // --- MCP ---

    public function testMcpSchemasDeclareImportance(): void
    {
        $r = $this->kernel->handle(new Request('POST', '/mcp', ['host' => self::HOST, 'content-type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])));
        $tools = [];
        foreach ($this->json($r)['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool['inputSchema'];
        }
        foreach (['create_task', 'update_task', 'list_tasks'] as $name) {
            $prop = $tools[$name]['properties']['importance'];
            self::assertSame('string', $prop['type'], $name);
            self::assertSame(self::VALUES, $prop['enum'], $name);
            self::assertNotContains('importance', $tools[$name]['required'] ?? [], $name);
        }
        foreach (['get_task', 'delete_task', 'add_required_action', 'remove_required_action', 'change_task_status'] as $name) {
            self::assertArrayNotHasKey('importance', (array) $tools[$name]['properties'], $name);
        }
    }

    public function testMcpCreateUpdateGetListAndParityWithRest(): void
    {
        $min = json_decode($this->mcpText('create_task', ['title' => 'T', 'project' => 'Divers Pro'], false), true);
        self::assertSame('Mineure', $min['importance']);
        $t = json_decode($this->mcpText('create_task', ['title' => 'U', 'project' => 'OPF', 'importance' => 'Critique'], false), true);
        self::assertSame('Critique', $t['importance']);
        $u = json_decode($this->mcpText('update_task', ['id' => $min['id'], 'importance' => 'Majeure'], false), true);
        self::assertSame('Majeure', $u['importance']);
        self::assertSame($u, json_decode($this->mcpText('get_task', ['id' => $min['id']], false), true));
        $list = json_decode($this->mcpText('list_tasks', ['importance' => 'Critique'], false), true);
        self::assertSame([$t['id']], array_column($list, 'id'));

        $arg = ['title' => 'T', 'project' => 'Divers Pro'];
        foreach (array_merge(self::BAD, [null, 4]) as $bad) {
            $mcp = $this->mcpText('create_task', $arg + ['importance' => $bad], true);
            $rest = $this->rest('POST', '/api/v1/tasks', $arg + ['importance' => $bad]);
            self::assertSame($rest->body, $mcp, 'parité octet pour octet (création)');
            $mcp = $this->mcpText('update_task', ['id' => $t['id'], 'importance' => $bad], true);
            $rest = $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['importance' => $bad]);
            self::assertSame($rest->body, $mcp, 'parité (modification)');
        }
        foreach (['', 'critique', ['x']] as $bad) {
            $mcp = $this->mcpText('list_tasks', ['importance' => $bad], true);
            $rest = $this->rest('GET', '/api/v1/tasks', null, ['importance' => $bad]);
            self::assertSame($rest->body, $mcp, 'parité (filtre)');
        }
        self::assertSame('Critique', $this->svc->get($t['id'])['importance']);
    }

    public function testMcpCompletionCopiesImportanceAndChangeStatusRefusesImportance(): void
    {
        $id = $this->make(['importance' => 'Majeure', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']])['id'];
        $this->mcpText('change_task_status', ['id' => $id, 'status' => 'in_progress'], false);
        $r = json_decode($this->mcpText('change_task_status', ['id' => $id, 'status' => 'done'], false), true);
        self::assertSame('Majeure', $r['importance']);
        self::assertSame('Majeure', $r['next_occurrence']['importance']);
        self::assertSame('field_unknown', json_decode($this->mcpText('change_task_status', ['id' => $id, 'status' => 'todo', 'importance' => 'Critique'], true), true)['error']['code']);
    }

    // --- Portail ---

    /** Options (valeur => sélectionnée) du contrôle `importance` d'une page. */
    private function options(string $html): array
    {
        self::assertSame(1, preg_match('#<select id="f-importance" name="importance"[^>]*>(.*?)</select>#s', $html, $m), 'liste Importance absente');
        preg_match_all('#<option value="([^"]*)"( selected)?>#', $m[1], $o, PREG_SET_ORDER);
        $out = [];
        foreach ($o as $opt) {
            $out[$opt[1]] = ($opt[2] ?? '') !== '';
        }

        return $out;
    }

    public function testPortalCreateFormHasListWithoutEmptyOptionAndMineureSelected(): void
    {
        $html = $this->portalGet('/tasks/new')->body;
        self::assertSame(['Mineure' => true, 'Majeure' => false, 'Critique' => false], $this->options($html));
        self::assertMatchesRegularExpression('#<label for="f-importance">Importance#', $html);
    }

    public function testPortalCreateAndEdit(): void
    {
        $r = $this->portalPost('/tasks', ['title' => 'P', 'project' => 'Divers Pro', 'importance' => 'Critique']);
        self::assertSame(303, $r->status);
        self::assertSame('Critique', $this->svc->get(1)['importance']);
        self::assertSame(['Mineure' => false, 'Majeure' => false, 'Critique' => true], $this->options($this->portalGet('/tasks/1/edit')->body));
        $r = $this->portalPost('/tasks/1', ['title' => 'P', 'project' => 'Divers Pro', 'importance' => 'Majeure']);
        self::assertSame(303, $r->status);
        self::assertSame('Majeure', $this->svc->get(1)['importance']);
        self::assertSame(['Mineure' => false, 'Majeure' => true, 'Critique' => false], $this->options($this->portalGet('/tasks/1/edit')->body));
    }

    public function testPortalRefusalKeepsInputAndMatchesRestRule(): void
    {
        $this->make(['importance' => 'Critique']);
        foreach (['', 'critique', 'Autre'] as $bad) {
            $rows = $this->rows();
            $r = $this->portalPost('/tasks', ['title' => 'Saisie conservée', 'project' => 'Divers Pro', 'importance' => $bad]);
            self::assertSame(422, $r->status, $bad);
            self::assertStringContainsString("L'importance doit être Mineure, Majeure ou Critique.", html_entity_decode($r->body, ENT_QUOTES));
            self::assertStringContainsString('id="e-importance"', $r->body);
            self::assertStringContainsString('href="#f-importance"', $r->body);
            self::assertStringContainsString('Saisie conservée', $r->body);
            self::assertSame($rows, $this->rows());
            self::assertSame(array_keys(['Mineure' => 1, 'Majeure' => 1, 'Critique' => 1]), array_keys($this->options($r->body)), 'toujours aucune option vide');

            $r = $this->portalPost('/tasks/1', ['title' => 'x', 'project' => 'Divers Pro', 'importance' => $bad]);
            self::assertSame(422, $r->status, $bad);
            self::assertSame('Critique', $this->rawImportance(1), 'tâche inchangée');
        }
    }

    public function testPortalForgedMissingFieldIsRefused(): void
    {
        $r = $this->portalPost('/tasks', ['title' => 'x', 'project' => 'Divers Pro']);
        self::assertSame(422, $r->status);
        self::assertSame(0, $this->rows());
    }

    public function testPortalListAndDetailShowImportanceAsText(): void
    {
        $this->make(['title' => 'Urgente', 'importance' => 'Critique']);
        $this->make(['title' => 'Banale']);
        $list = $this->portalGet('/tasks')->body;
        self::assertMatchesRegularExpression('#<th scope="col">Importance</th>#', $list);
        self::assertMatchesRegularExpression('#<td>Critique</td>#', $list);
        self::assertMatchesRegularExpression('#<td>Mineure</td>#', $list);
        $detail = $this->portalGet('/tasks/1')->body;
        self::assertMatchesRegularExpression('#<dt>Importance</dt>\s*<dd>Critique</dd>#', $detail);
    }

    public function testPortalFilter(): void
    {
        $this->make(['title' => 'A-crit-pro', 'importance' => 'Critique', 'project' => 'Divers Pro']);
        $this->make(['title' => 'B-crit-opf', 'importance' => 'Critique', 'project' => 'OPF']);
        $c = $this->make(['title' => 'C-crit-done', 'importance' => 'Critique', 'project' => 'OPF'])['id'];
        $this->make(['title' => 'D-maj', 'importance' => 'Majeure']);
        $this->svc->changeStatus($c, 'in_progress');
        $this->svc->changeStatus($c, 'done');

        $html = $this->portalGet('/tasks', ['importance' => 'Critique'])->body;
        self::assertSame(['Critique' => true], array_filter($this->filterOptions($html)), 'valeur sélectionnée conservée');
        foreach (['A-crit-pro', 'B-crit-opf', 'C-crit-done'] as $t) {
            self::assertStringContainsString($t, $html);
        }
        self::assertStringNotContainsString('D-maj', $html);
        self::assertStringContainsString('Filtres actifs', $html);

        $html = $this->portalGet('/tasks', ['importance' => 'Critique', 'project' => 'OPF', 'hide_done' => '1'])->body;
        self::assertStringContainsString('B-crit-opf', $html);
        foreach (['A-crit-pro', 'C-crit-done', 'D-maj'] as $t) {
            self::assertStringNotContainsString($t, $html);
        }
        $html = $this->portalGet('/tasks', ['importance' => 'Critique', 'category' => 'Pro', 'status' => 'todo'])->body;
        self::assertStringContainsString('A-crit-pro', $html);
        self::assertStringNotContainsString('B-crit-opf', $html);

        // « Toutes » : valeur vide = filtre absent.
        $html = $this->portalGet('/tasks', ['importance' => ''])->body;
        self::assertStringContainsString('D-maj', $html);
        self::assertStringNotContainsString('Filtres actifs', $html);
        self::assertArrayHasKey('', $this->filterOptions($html));

        $r = $this->portalGet('/tasks', ['importance' => 'critique']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Filtre invalide', $r->body);
    }

    /** @return array<string,bool> */
    private function filterOptions(string $html): array
    {
        self::assertSame(1, preg_match('#<select id="f-importance" name="importance">(.*?)</select>#s', $html, $m), 'filtre Importance absent');
        preg_match_all('#<option value="([^"]*)"( selected)?>#', $m[1], $o, PREG_SET_ORDER);
        $out = [];
        foreach ($o as $opt) {
            $out[$opt[1]] = ($opt[2] ?? '') !== '';
        }

        return $out;
    }

    public function testPortalCompletionShowsNextOccurrenceWithCopiedImportance(): void
    {
        $id = $this->make(['importance' => 'Critique', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']])['id'];
        $this->portalPost('/tasks/' . $id . '/status', ['status' => 'in_progress']);
        $this->portalPost('/tasks/' . $id . '/status', ['status' => 'done']);
        self::assertSame('Critique', $this->svc->get($id + 1)['importance']);
        self::assertMatchesRegularExpression('#<dt>Importance</dt>\s*<dd>Critique</dd>#', $this->portalGet('/tasks/' . ($id + 1))->body);
    }

    public function testImportanceIsEscapedNowhereBecauseOnlyListedValuesExist(): void
    {
        // Une valeur forgée n'est jamais rendue comme valeur de liste : seule la liste fermée est affichée.
        $r = $this->portalGet('/tasks', ['importance' => '<script>x</script>']);
        self::assertSame(422, $r->status);
        self::assertStringNotContainsString('<script>x</script>', $r->body);
    }
}
