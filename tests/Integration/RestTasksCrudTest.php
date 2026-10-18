<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Config;
use Actions\Infrastructure\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;

/** CRUD REST des tâches (POST, liste, PATCH) : Kernel -> RestAdapter -> TaskService -> PostgreSQL réel. */
final class RestTasksCrudTest extends DbTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->kernel = Bootstrap::kernel($this->config);
    }

    private function send(string $method, string $path, ?string $body = null, ?Kernel $kernel = null): Response
    {
        return ($kernel ?? $this->kernel)->handle(new Request($method, $path, [], $body ?? ''));
    }

    /** @param array<string,mixed> $data */
    private function post(array $data): Response
    {
        return $this->send('POST', '/api/v1/tasks', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $data */
    private function patch(int $id, array $data): Response
    {
        return $this->send('PATCH', "/api/v1/tasks/$id", json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return mixed */
    private function json(Response $r): mixed
    {
        self::assertSame('application/json', $r->headers['Content-Type']);

        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function assertError(Response $r, int $status, string $code, ?string $field = null): array
    {
        self::assertSame($status, $r->status, $r->body);
        $body = $this->json($r);
        self::assertSame(['error'], array_keys($body));
        self::assertSame($code, $body['error']['code']);
        self::assertIsString($body['error']['message']);
        if ($field !== null) {
            self::assertSame($field, $body['error']['details']['field']);
        }

        return $body['error'];
    }

    /** @return array<string,mixed> */
    private function created(array $data): array
    {
        $r = $this->post($data);
        self::assertSame(201, $r->status, $r->body);

        return $this->json($r);
    }

    private function backdate(int $id): void
    {
        $this->pdo->exec("UPDATE tasks SET updated_at = now() - interval '1 day' WHERE id = $id");
    }

    private function updatedAt(int $id): string
    {
        return (string) $this->pdo->query("SELECT updated_at::text FROM tasks WHERE id = $id")->fetchColumn();
    }

    // --- POST ---

    public function testPostMinimalGives201WithLocationAndNullOptionals(): void
    {
        $r = $this->post(['title' => 'Écrire', 'project' => 'Divers Pro']);
        self::assertSame(201, $r->status);
        $task = $this->json($r);
        self::assertSame('/api/v1/tasks/' . $task['id'], $r->headers['Location']);
        self::assertSame('Écrire', $task['title']);
        self::assertSame('Pro', $task['category']);
        foreach (['due_date', 'recurrence', 'estimated_minutes', 'description'] as $f) {
            self::assertArrayHasKey($f, $task);
            self::assertNull($task[$f]);
        }
        self::assertSame([], $task['required_actions']);
        self::assertNotSame('', $task['created_at']);
        self::assertSame($task['created_at'], $task['updated_at']);
        self::assertSame($task, $this->json($this->send('GET', '/api/v1/tasks/' . $task['id'])));
    }

    public function testPostFullReturnsGivenValuesAndRoundTrips(): void
    {
        $in = [
            'title' => 'Facture', 'project' => 'Divers Perso', 'due_date' => '2020-02-29',
            'recurrence' => ['interval' => 2, 'unit' => 'week'], 'estimated_minutes' => 90, 'description' => "Ligne 1\nLigne 2",
        ];
        $task = $this->created($in);
        foreach ($in as $k => $v) {
            self::assertSame($v, $task[$k]);
        }
        self::assertSame($task, $this->json($this->send('GET', '/api/v1/tasks/' . $task['id'])));
    }

    public function testPostKeepsTitleAsIsAndAccepts255Characters(): void
    {
        self::assertSame('  a  ', $this->created(['title' => '  a  ', 'project' => 'Divers Pro'])['title']);
        $long = str_repeat('é', 255);
        self::assertSame($long, $this->created(['title' => $long, 'project' => 'Divers Pro'])['title']);
    }

    public function testPostAcceptsExplicitNullOptionals(): void
    {
        $task = $this->created(['title' => 'T', 'project' => 'Divers Pro', 'due_date' => null, 'recurrence' => null, 'estimated_minutes' => null, 'description' => null]);
        self::assertNull($task['due_date']);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
    public static function invalidCreateProvider(): array
    {
        $ok = ['title' => 'T', 'project' => 'Divers Pro'];

        return [
            'titre blanc' => [['title' => '   ', 'project' => 'Divers Pro'], 'title_blank', 'title'],
            'titre tabulations' => [['title' => "\t\n", 'project' => 'Divers Pro'], 'title_blank', 'title'],
            'titre absent' => [['project' => 'Divers Pro'], 'title_blank', 'title'],
            'titre 256' => [['title' => str_repeat('a', 256), 'project' => 'Divers Pro'], 'title_too_long', 'title'],
            'titre null' => [['title' => null, 'project' => 'Divers Pro'], 'field_type_invalid', 'title'],
            'titre nombre' => [['title' => 12, 'project' => 'Divers Pro'], 'field_type_invalid', 'title'],
            'catégorie refusée (casse)' => [['title' => 'T', 'project' => 'Divers Pro', 'category' => 'pro'], 'field_unknown', 'category'],
            'catégorie refusée (valide)' => [['title' => 'T', 'project' => 'Divers Pro', 'category' => 'Pro'], 'field_unknown', 'category'],
            'catégorie null refusée' => [['title' => 'T', 'project' => 'Divers Pro', 'category' => null], 'field_unknown', 'category'],
            'projet absent' => [['title' => 'T'], 'project_invalid', 'project'],
            'projet null' => [['title' => 'T', 'project' => null], 'project_invalid', 'project'],
            'projet inconnu' => [['title' => 'T', 'project' => 'Inexistant'], 'project_invalid', 'project'],
            'projet casse' => [['title' => 'T', 'project' => 'divers pro'], 'project_invalid', 'project'],
            'projet nombre' => [['title' => 'T', 'project' => 1], 'field_type_invalid', 'project'],
            'date inexistante' => [$ok + ['due_date' => '2026-02-30'], 'due_date_invalid', 'due_date'],
            'date mal formée' => [$ok + ['due_date' => '12/10/2026'], 'due_date_invalid', 'due_date'],
            'date non chaîne' => [$ok + ['due_date' => 20261012], 'field_type_invalid', 'due_date'],
            'estimation 0' => [$ok + ['estimated_minutes' => 0], 'estimated_minutes_invalid', 'estimated_minutes'],
            'estimation décimale' => [$ok + ['estimated_minutes' => 1.5], 'field_type_invalid', 'estimated_minutes'],
            'estimation chaîne' => [$ok + ['estimated_minutes' => '10'], 'field_type_invalid', 'estimated_minutes'],
            'intervalle 0' => [$ok + ['due_date' => '2026-10-12', 'recurrence' => ['interval' => 0, 'unit' => 'day']], 'recurrence_interval_invalid', 'recurrence.interval'],
            'unité inconnue' => [$ok + ['due_date' => '2026-10-12', 'recurrence' => ['interval' => 1, 'unit' => 'hour']], 'recurrence_unit_invalid', 'recurrence.unit'],
            'périodicité incomplète' => [$ok + ['due_date' => '2026-10-12', 'recurrence' => ['interval' => 1]], 'recurrence_incomplete', 'recurrence'],
            'périodicité sans date' => [$ok + ['recurrence' => ['interval' => 1, 'unit' => 'day']], 'recurrence_requires_due_date', 'recurrence'],
            'clé inconnue' => [$ok + ['color' => 'red'], 'field_unknown', 'color'],
            'clé inconnue de périodicité' => [$ok + ['due_date' => '2026-10-12', 'recurrence' => ['interval' => 1, 'unit' => 'day', 'x' => 1]], 'field_unknown', 'recurrence.x'],
            'id fourni' => [$ok + ['id' => 5], 'field_unknown', 'id'],
            'required_actions fourni' => [$ok + ['required_actions' => []], 'field_unknown', 'required_actions'],
            'created_at fourni' => [$ok + ['created_at' => '2026-01-01T00:00:00Z'], 'field_unknown', 'created_at'],
            'updated_at fourni' => [$ok + ['updated_at' => '2026-01-01T00:00:00Z'], 'field_unknown', 'updated_at'],
        ];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('invalidCreateProvider')]
    public function testPostInvalidGives422AndCreatesNothing(array $body, string $code, string $field): void
    {
        $this->assertError($this->post($body), 422, $code, $field);
        self::assertSame(0, $this->rows());
    }

    /** @return array<string,array{0:string}> */
    public static function invalidBodyProvider(): array
    {
        return [
            'vide' => [''], 'accolade ouverte' => ['{'], 'tableau vide' => ['[]'], 'liste' => ['[1]'],
            'chaîne' => ['"x"'], 'null' => ['null'], 'nombre' => ['123'], 'booléen' => ['true'],
            'texte' => ['pas du json'], 'profondeur' => [str_repeat('[', 40) . str_repeat(']', 40)],
        ];
    }

    #[DataProvider('invalidBodyProvider')]
    public function testPostInvalidJsonGives400WithoutParserDetail(string $body): void
    {
        $r = $this->send('POST', '/api/v1/tasks', $body);
        $error = $this->assertError($r, 400, 'invalid_json');
        self::assertSame('Le corps doit être un objet JSON.', $error['message']);
        self::assertArrayNotHasKey('details', $error);
        foreach (['Syntax', 'syntax', 'JSON_', 'depth', 'json_decode'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
        self::assertSame(0, $this->rows());
    }

    public function testPostEmptyObjectIsValidJsonButInvalidTask(): void
    {
        $this->assertError($this->send('POST', '/api/v1/tasks', '{}'), 422, 'title_blank', 'title');
    }

    public function testPostRecurrenceGivenAsListIsRefused(): void
    {
        $r = $this->send('POST', '/api/v1/tasks', '{"title":"T","project":"Divers Pro","due_date":"2026-10-12","recurrence":[1,"day"]}');
        self::assertSame(422, $r->status);
        self::assertSame(0, $this->rows());
    }

    public function testPostIgnoresContentTypeAndQueryString(): void
    {
        $r = $this->kernel->handle(new Request('POST', '/api/v1/tasks', ['content-type' => 'text/plain'], '{"title":"T","project":"Divers Pro"}'));
        self::assertSame(201, $r->status);
    }

    public function testPostBigIntegerIsRefused(): void
    {
        $r = $this->send('POST', '/api/v1/tasks', '{"title":"T","project":"Divers Pro","estimated_minutes":99999999999999999999}');
        $this->assertError($r, 422, 'field_type_invalid', 'estimated_minutes');
    }

    // --- GET /tasks ---

    public function testListEmptyGivesEmptyArray(): void
    {
        $r = $this->send('GET', '/api/v1/tasks');
        self::assertSame(200, $r->status);
        self::assertSame('[]', $r->body);
    }

    public function testListIsSortedByDueDateThenIdWithUndatedLast(): void
    {
        $nodate1 = $this->created(['title' => 'sans date 1', 'project' => 'Divers Pro'])['id'];
        $late = $this->created(['title' => 'tard', 'project' => 'Divers Pro', 'due_date' => '2026-12-01'])['id'];
        $early1 = $this->created(['title' => 'tôt 1', 'project' => 'Divers Perso', 'due_date' => '2026-01-01'])['id'];
        $nodate2 = $this->created(['title' => 'sans date 2', 'project' => 'Divers Pro'])['id'];
        $early2 = $this->created(['title' => 'tôt 2', 'project' => 'Divers Pro', 'due_date' => '2026-01-01'])['id'];

        $r = $this->send('GET', '/api/v1/tasks');
        self::assertSame(200, $r->status);
        $list = $this->json($r);
        self::assertSame([$early1, $early2, $late, $nodate1, $nodate2], array_column($list, 'id'));
        self::assertSame($this->json($this->send('GET', "/api/v1/tasks/$late")), $list[2]);
    }

    // --- PATCH ---

    public function testPatchChangesOnlyGivenFieldsAndAdvancesUpdatedAt(): void
    {
        $t = $this->created(['title' => 'A', 'project' => 'Divers Pro', 'due_date' => '2026-10-12', 'estimated_minutes' => 30, 'description' => 'd']);
        $id = $t['id'];
        $this->backdate($id);
        $before = $this->updatedAt($id);
        $r = $this->patch($id, ['title' => 'B']);
        self::assertSame(200, $r->status);
        $new = $this->json($r);
        self::assertSame('B', $new['title']);
        foreach (['category', 'due_date', 'estimated_minutes', 'description', 'created_at', 'required_actions'] as $f) {
            self::assertSame($t[$f], $new[$f], $f);
        }
        self::assertGreaterThan($before, $this->updatedAt($id));
        self::assertSame($t['created_at'], $new['created_at']);
        self::assertSame($t['created_at'], $this->json($this->send('GET', "/api/v1/tasks/$id"))['created_at']);
    }

    public function testPatchNullClearsEachOptionalField(): void
    {
        $id = $this->created([
            'title' => 'A', 'project' => 'Divers Pro', 'due_date' => '2026-10-12',
            'recurrence' => ['interval' => 1, 'unit' => 'day'], 'estimated_minutes' => 30, 'description' => 'd',
        ])['id'];
        foreach (['recurrence', 'estimated_minutes', 'description'] as $f) {
            $r = $this->patch($id, [$f => null]);
            self::assertSame(200, $r->status);
            self::assertNull($this->json($r)[$f]);
        }
        $r = $this->patch($id, ['due_date' => null]);
        self::assertSame(200, $r->status);
        self::assertNull($this->json($r)['due_date']);
    }

    public function testPatchEmptyObjectAndIdenticalValuesAreNoOps(): void
    {
        $id = $this->created(['title' => 'A', 'project' => 'Divers Pro'])['id'];
        $this->backdate($id);
        $before = $this->updatedAt($id);
        $r = $this->send('PATCH', "/api/v1/tasks/$id", '{}');
        self::assertSame(200, $r->status);
        self::assertSame('A', $this->json($r)['title']);
        self::assertSame(200, $this->patch($id, ['title' => 'A', 'project' => 'Divers Pro'])->status);
        self::assertSame($before, $this->updatedAt($id));
    }

    public function testPatchClearingDueDateOfRecurringTaskIsRefusedAndTaskUnchanged(): void
    {
        $t = $this->created(['title' => 'A', 'project' => 'Divers Pro', 'due_date' => '2026-10-12', 'recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $this->assertError($this->patch($t['id'], ['due_date' => null]), 422, 'recurrence_requires_due_date', 'recurrence');
        self::assertSame($t, $this->json($this->send('GET', '/api/v1/tasks/' . $t['id'])));
    }

    public function testPatchRecurrenceOnTaskWithoutDueDateIsRefused(): void
    {
        $id = $this->created(['title' => 'A', 'project' => 'Divers Pro'])['id'];
        $this->assertError($this->patch($id, ['recurrence' => ['interval' => 1, 'unit' => 'day']]), 422, 'recurrence_requires_due_date');
    }

    /** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
    public static function invalidPatchProvider(): array
    {
        return [
            'titre null' => [['title' => null], 'field_type_invalid', 'title'],
            'catégorie null refusée' => [['category' => null], 'field_unknown', 'category'],
            'titre blanc' => [['title' => ' '], 'title_blank', 'title'],
            'catégorie refusée' => [['category' => 'Autre'], 'field_unknown', 'category'],
            'projet null' => [['project' => null], 'project_invalid', 'project'],
            'projet inconnu' => [['project' => 'Inexistant'], 'project_invalid', 'project'],
            'id' => [['id' => 9], 'field_unknown', 'id'],
            'required_actions' => [['required_actions' => [1]], 'field_unknown', 'required_actions'],
            'created_at' => [['created_at' => 'x'], 'field_unknown', 'created_at'],
            'updated_at' => [['updated_at' => 'x'], 'field_unknown', 'updated_at'],
            'inconnue avec valide' => [['title' => 'Nouveau', 'foo' => 1], 'field_unknown', 'foo'],
        ];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('invalidPatchProvider')]
    public function testPatchInvalidGives422AndTaskUnchanged(array $body, string $code, string $field): void
    {
        $t = $this->created(['title' => 'A', 'project' => 'Divers Pro']);
        $this->assertError($this->patch($t['id'], $body), 422, $code, $field);
        self::assertSame($t, $this->json($this->send('GET', '/api/v1/tasks/' . $t['id'])));
    }

    public function testPatchUnknownIdGives404WithValidJson(): void
    {
        $error = $this->assertError($this->patch(999, ['title' => 'x']), 404, 'task_not_found');
        self::assertSame(['id' => 999], $error['details']);
        $this->assertError($this->send('PATCH', '/api/v1/tasks/999', '{}'), 404, 'task_not_found');
        // 404 prime sur une validation qui échouerait
        $this->assertError($this->patch(999, ['category' => 'zzz']), 404, 'task_not_found');
    }

    #[DataProvider('invalidBodyProvider')]
    public function testPatchInvalidJsonGives400EvenForUnknownId(string $body): void
    {
        $this->assertError($this->send('PATCH', '/api/v1/tasks/999', $body), 400, 'invalid_json');
        $id = $this->created(['title' => 'A', 'project' => 'Divers Pro'])['id'];
        $this->assertError($this->send('PATCH', "/api/v1/tasks/$id", $body), 400, 'invalid_json');
        self::assertSame('A', $this->json($this->send('GET', "/api/v1/tasks/$id"))['title']);
    }

    public function testPatchInvalidIdInPathGives404NotFound(): void
    {
        $this->assertError($this->send('PATCH', '/api/v1/tasks/abc', '{}'), 404, 'not_found');
        $this->assertError($this->send('PATCH', '/api/v1/tasks/abc', 'pas du json'), 404, 'not_found');
    }

    public function testPatchKeepsRequiredActions(): void
    {
        $a = $this->created(['title' => 'A', 'project' => 'Divers Pro'])['id'];
        $b = $this->created(['title' => 'B', 'project' => 'Divers Pro'])['id'];
        self::assertSame(200, $this->send('PUT', "/api/v1/tasks/$a/required-actions/$b")->status);
        $task = $this->json($this->patch($a, ['title' => 'A2']));
        self::assertSame([['id' => $b, 'title' => 'B']], $task['required_actions']);
    }

    // --- DELETE inchangé, 405, erreurs ---

    public function testDeleteUnknownAndTwiceGive404(): void
    {
        $this->assertError($this->send('DELETE', '/api/v1/tasks/999'), 404, 'task_not_found');
        $id = $this->created(['title' => 'A', 'project' => 'Divers Pro'])['id'];
        self::assertSame(204, $this->send('DELETE', "/api/v1/tasks/$id")->status);
        $this->assertError($this->send('DELETE', "/api/v1/tasks/$id"), 404, 'task_not_found');
    }

    public function testWrongMethodsGive405WithAllow(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $m) {
            $r = $this->send($m, '/api/v1/tasks');
            $this->assertError($r, 405, 'method_not_allowed');
            self::assertSame('GET, POST', $r->headers['Allow']);
        }
        $id = $this->created(['title' => 'A', 'project' => 'Divers Pro'])['id'];
        foreach (['PUT', 'POST'] as $m) {
            $r = $this->send($m, "/api/v1/tasks/$id", '{"title":"B","project":"Divers Pro"}');
            $this->assertError($r, 405, 'method_not_allowed');
            self::assertSame('GET, PATCH, DELETE', $r->headers['Allow']);
        }
        self::assertSame('A', $this->json($this->send('GET', "/api/v1/tasks/$id"))['title']);
        self::assertSame(1, $this->rows());
    }

    public function testPostWithPdoExceptionGives500WithoutLeak(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $e = new \PDOException('SECRET_MESSAGE relation "tasks" SELECT secret_col');
        $e->errorInfo = ['XX000', 7, 'SECRET_DRIVER_MESSAGE'];
        $pdo->method('prepare')->willThrowException($e);
        $pdo->method('beginTransaction')->willThrowException($e);
        $kernel = $this->kernelWith($pdo);
        foreach ([['POST', '/api/v1/tasks', '{"title":"T","project":"Divers Pro"}'], ['GET', '/api/v1/tasks', null], ['PATCH', '/api/v1/tasks/1', '{"title":"T"}']] as [$m, $p, $b]) {
            $r = $this->send($m, $p, $b, $kernel);
            self::assertSame(['error' => ['code' => 'internal_error', 'message' => 'Erreur interne.']], $this->assertErrorBody($r, 500));
            foreach (['XX000', 'SECRET', 'SELECT', 'tasks"', 'PDO'] as $leak) {
                self::assertStringNotContainsString($leak, $r->body);
            }
        }
    }

    public function testUnreachableDatabaseGives503(): void
    {
        $kernel = Bootstrap::kernel(new Config('test', '127.0.0.1', 1, 'secret_db', 'secret_user', 'secret_pwd', []));
        foreach ([['POST', '/api/v1/tasks', '{"title":"T","project":"Divers Pro"}'], ['GET', '/api/v1/tasks', null], ['PATCH', '/api/v1/tasks/1', '{}']] as [$m, $p, $b]) {
            $r = $this->send($m, $p, $b, $kernel);
            $this->assertError($r, 503, 'service_unavailable');
            foreach (['127.0.0.1', 'secret_db', 'secret_user', 'secret_pwd', 'SQLSTATE'] as $leak) {
                self::assertStringNotContainsString($leak, $r->body);
            }
        }
    }

    /** @return array<string,mixed> */
    private function assertErrorBody(Response $r, int $status): array
    {
        self::assertSame($status, $r->status);

        return $this->json($r);
    }

    private function kernelWith(\PDO $pdo): Kernel
    {
        $diagnostic = new \Actions\Application\Diagnostic(static fn (): \PDO => $pdo);
        $connect = static fn (): \PDO => $pdo;
        $service = \Actions\Tests\Support\Services::tasks($connect);
        $projects = \Actions\Tests\Support\Services::projects($connect);

        return new Kernel(
            new \Actions\Portal\PortalAdapter($service, $projects, \Actions\Tests\Support\Services::overview($connect), new \Actions\Tests\Support\ArraySession(), ['127.0.0.1']),
            new \Actions\Rest\RestAdapter($diagnostic, new \Actions\Rest\TaskController($service), new \Actions\Rest\ProjectController($projects)),
            new \Actions\Mcp\McpAdapter($diagnostic, $service, $projects, [], ['127.0.0.1']),
        );
    }

    // --- Parcours ---

    public function testEndToEndJourney(): void
    {
        $task = $this->created(['title' => 'Parcours', 'project' => 'Divers Perso']);
        $id = $task['id'];
        self::assertSame($task, $this->json($this->send('GET', "/api/v1/tasks/$id")));
        $patched = $this->json($this->patch($id, ['description' => 'maj', 'due_date' => '2026-11-01']));
        self::assertSame('maj', $patched['description']);
        $list = $this->json($this->send('GET', '/api/v1/tasks'));
        self::assertSame([$patched], $list);
        self::assertSame(204, $this->send('DELETE', "/api/v1/tasks/$id")->status);
        $this->assertError($this->send('GET', "/api/v1/tasks/$id"), 404, 'task_not_found');
        self::assertSame('[]', $this->send('GET', '/api/v1/tasks')->body);
    }
}
