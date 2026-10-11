<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Migrator;

/** Cycle de vie en REST (PATCH avec status, filtres de GET /tasks) : Kernel -> RestAdapter -> TaskService -> PostgreSQL réel. */
final class RestTaskStatusTest extends DbTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, schema_migrations CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->migrate();
        $this->kernel = Bootstrap::kernel($this->config);
    }

    /** @param array<string,mixed> $query */
    private function send(string $method, string $path, string $body = '', array $query = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, [], $body, $query));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function create(array $data = []): array
    {
        $r = $this->send('POST', '/api/v1/tasks', json_encode($data + ['title' => 't', 'category' => 'Pro'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $r->status, $r->body);

        return $this->json($r);
    }

    /** @param array<string,mixed> $data */
    private function patch(int $id, array $data): Response
    {
        return $this->send('PATCH', "/api/v1/tasks/$id", json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function json(Response $r): mixed
    {
        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }

    private function force(int $id, string $status): void
    {
        $completed = $status === 'done' ? 'now()' : 'NULL';
        $this->pdo->exec("UPDATE tasks SET status = '$status', completed_at = $completed WHERE id = $id");
    }

    /** @return array<string,mixed> */
    private function raw(int $id): array
    {
        return $this->pdo->query("SELECT * FROM tasks WHERE id = $id")->fetch();
    }

    private function assertError(Response $r, int $status, string $code): array
    {
        self::assertSame($status, $r->status, $r->body);
        $body = $this->json($r);
        self::assertSame($code, $body['error']['code']);

        return $body['error'];
    }

    // --- forme de Task ---

    public function testTaskExposesStatusAndCompletedAtEverywhere(): void
    {
        $created = $this->create();
        self::assertSame('todo', $created['status']);
        self::assertNull($created['completed_at']);
        self::assertArrayNotHasKey('next_occurrence', $created);

        self::assertSame('todo', $this->json($this->send('GET', "/api/v1/tasks/{$created['id']}"))['status']);
        self::assertSame('todo', $this->json($this->send('GET', '/api/v1/tasks'))[0]['status']);
        $other = $this->create();
        $linked = $this->json($this->send('PUT', "/api/v1/tasks/{$created['id']}/required-actions/{$other['id']}"));
        self::assertSame('todo', $linked['status']);
        self::assertArrayHasKey('completed_at', $linked);
        $unlinked = $this->json($this->send('DELETE', "/api/v1/tasks/{$created['id']}/required-actions/{$other['id']}"));
        self::assertArrayHasKey('completed_at', $unlinked);
    }

    public function testPostRefusesStatusAndCompletedAt(): void
    {
        foreach (['status', 'completed_at'] as $key) {
            $this->assertError($this->send('POST', '/api/v1/tasks', json_encode(['title' => 't', 'category' => 'Pro', $key => 'done'])), 422, 'field_unknown');
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn());
    }

    // --- PATCH status ---

    public function testPatchStatusChangesStateAndReturnsFlatTaskWithNullNextOccurrence(): void
    {
        $task = $this->create();
        $r = $this->patch($task['id'], ['status' => 'in_progress']);
        self::assertSame(200, $r->status, $r->body);
        $body = $this->json($r);
        self::assertSame('in_progress', $body['status']);
        self::assertSame($task['id'], $body['id']);
        self::assertArrayHasKey('next_occurrence', $body);
        self::assertNull($body['next_occurrence']);

        $done = $this->json($this->patch($task['id'], ['status' => 'done']));
        self::assertSame('done', $done['status']);
        self::assertNotNull($done['completed_at']);
        self::assertNull($done['next_occurrence']);
    }

    public function testPatchWithoutStatusHasNoNextOccurrenceKey(): void
    {
        $task = $this->create();
        $body = $this->json($this->patch($task['id'], ['title' => 'autre']));
        self::assertSame('autre', $body['title']);
        self::assertArrayNotHasKey('next_occurrence', $body);
    }

    public function testCompletingRecurringTaskReturnsNextOccurrence(): void
    {
        $task = $this->create(['due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month'], 'description' => 'd']);
        $this->patch($task['id'], ['status' => 'in_progress']);
        $body = $this->json($this->patch($task['id'], ['status' => 'done']));
        self::assertSame('done', $body['status']);
        $next = $body['next_occurrence'];
        self::assertSame('2026-02-28', $next['due_date']);
        self::assertSame('todo', $next['status']);
        self::assertNull($next['completed_at']);
        self::assertSame('d', $next['description']);
        self::assertSame([], $next['required_actions']);
        self::assertArrayNotHasKey('next_occurrence', $next);
        self::assertSame($next, $this->json($this->send('GET', "/api/v1/tasks/{$next['id']}")));
    }

    public function testPatchStatusAndOtherFieldIs422(): void
    {
        $task = $this->create();
        $e = $this->assertError($this->patch($task['id'], ['status' => 'in_progress', 'title' => 'x']), 422, 'status_not_alone');
        self::assertSame(['field' => 'status'], $e['details']);
        self::assertSame('todo', $this->raw($task['id'])['status']);
    }

    public function testPatchInvalidStatusIs422(): void
    {
        $task = $this->create();
        self::assertSame(['field' => 'status'], $this->assertError($this->patch($task['id'], ['status' => 'bidon']), 422, 'status_invalid')['details']);
        $this->assertError($this->patch($task['id'], ['status' => '']), 422, 'status_invalid');
        $this->assertError($this->patch($task['id'], ['status' => null]), 422, 'field_type_invalid');
        $this->assertError($this->patch($task['id'], ['status' => 3]), 422, 'field_type_invalid');
        $this->assertError($this->patch($task['id'], ['status' => ['done']]), 422, 'field_type_invalid');
    }

    public function testPatchRefusedTransitionIs409AndLeavesTaskUntouched(): void
    {
        $task = $this->create();
        $this->pdo->exec("UPDATE tasks SET updated_at = '2000-01-01 00:00:00+00'");
        $before = $this->raw($task['id']);
        $e = $this->assertError($this->patch($task['id'], ['status' => 'done']), 409, 'status_transition_invalid');
        self::assertSame(['id' => $task['id'], 'from' => 'todo', 'to' => 'done'], $e['details']);
        $this->assertError($this->patch($task['id'], ['status' => 'todo']), 409, 'status_transition_invalid');
        self::assertSame($before, $this->raw($task['id']));
    }

    public function testPatchDoneWithUnfinishedRequiredActionsIs409WithList(): void
    {
        $main = $this->create(['title' => 'main']);
        $req = $this->create(['title' => 'req']);
        $this->send('PUT', "/api/v1/tasks/{$main['id']}/required-actions/{$req['id']}");
        $this->patch($main['id'], ['status' => 'in_progress']);
        $e = $this->assertError($this->patch($main['id'], ['status' => 'done']), 409, 'required_actions_not_done');
        self::assertSame(['id' => $main['id'], 'required_actions' => [['id' => $req['id'], 'title' => 'req', 'status' => 'todo']]], $e['details']);
        self::assertSame('in_progress', $this->raw($main['id'])['status']);
    }

    public function testPatchDoneOutOfRangeIs409(): void
    {
        $task = $this->create(['due_date' => '9999-12-31', 'recurrence' => ['interval' => 1, 'unit' => 'year']]);
        $this->patch($task['id'], ['status' => 'in_progress']);
        $e = $this->assertError($this->patch($task['id'], ['status' => 'done']), 409, 'next_due_date_out_of_range');
        self::assertSame(['id' => $task['id'], 'due_date' => '9999-12-31'], $e['details']);
        self::assertSame('in_progress', $this->raw($task['id'])['status']);
        self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn());
    }

    public function testPatchUnknownTaskIs404AndInvalidJsonTakesPrecedence(): void
    {
        $this->assertError($this->patch(999, ['status' => 'done']), 404, 'task_not_found');
        $this->assertError($this->patch(999, ['status' => 'done', 'title' => 'x']), 404, 'task_not_found');
        $this->assertError($this->send('PATCH', '/api/v1/tasks/999', '{pas du json'), 400, 'invalid_json');
    }

    // --- GET /tasks filtré ---

    public function testListFilters(): void
    {
        $a = $this->create(['title' => 'a']);
        $b = $this->create(['title' => 'b', 'category' => 'Perso']);
        $c = $this->create(['title' => 'c', 'category' => 'Perso']);
        $this->force($b['id'], 'in_progress');
        $this->force($c['id'], 'done');
        $ids = fn (Response $r): array => array_column($this->json($r), 'id');

        self::assertSame([$a['id'], $b['id'], $c['id']], $ids($this->send('GET', '/api/v1/tasks')));
        self::assertSame([$b['id']], $ids($this->send('GET', '/api/v1/tasks', '', ['status' => 'in_progress'])));
        self::assertSame([$b['id'], $c['id']], $ids($this->send('GET', '/api/v1/tasks', '', ['category' => 'Perso'])));
        self::assertSame([$c['id']], $ids($this->send('GET', '/api/v1/tasks', '', ['status' => 'done', 'category' => 'Perso'])));
        self::assertSame([], $this->json($this->send('GET', '/api/v1/tasks', '', ['status' => 'done', 'category' => 'Pro'])));
    }

    public function testListIgnoresUnknownQueryParameters(): void
    {
        $this->create();
        $r = $this->send('GET', '/api/v1/tasks', '', ['page' => '2', 'tri' => 'x']);
        self::assertSame(200, $r->status);
        self::assertCount(1, $this->json($r));
    }

    public function testListInvalidFiltersAre422WithField(): void
    {
        $this->create();
        foreach ([
            [['status' => 'bidon'], 'status_invalid', 'status'],
            [['status' => ''], 'status_invalid', 'status'],
            [['category' => 'pro'], 'category_invalid', 'category'],
            [['category' => ''], 'category_invalid', 'category'],
            [['status' => ['done']], 'field_type_invalid', 'status'],
            [['category' => ['Pro', 'Perso']], 'field_type_invalid', 'category'],
            [['status' => 'done', 'category' => 'x'], 'category_invalid', 'category'],
        ] as [$query, $code, $field]) {
            $e = $this->assertError($this->send('GET', '/api/v1/tasks', '', $query), 422, $code);
            self::assertSame(['field' => $field], $e['details']);
        }
    }

    public function testListQueryIsReadFromTheRealUri(): void
    {
        // Request::fromGlobals alimente `query` depuis REQUEST_URI.
        $saved = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/v1/tasks?status=done&category[]=Pro&x=%C3%A9';
            $request = Request::fromGlobals();
            self::assertSame('/api/v1/tasks', $request->path);
            self::assertSame(['status' => 'done', 'category' => ['Pro'], 'x' => 'é'], $request->query);
            $_SERVER['REQUEST_URI'] = '/api/v1/tasks';
            self::assertSame([], Request::fromGlobals()->query);
        } finally {
            $_SERVER = $saved;
        }
    }
}
