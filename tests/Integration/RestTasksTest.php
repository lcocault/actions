<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\Diagnostic;
use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Config;
use Actions\Infrastructure\Migrator;
use Actions\Mcp\McpAdapter;
use Actions\Portal\PortalAdapter;
use Actions\Rest\RestAdapter;
use Actions\Rest\TaskController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * REST des tâches (liens, lecture, suppression) de bout en bout : Kernel -> RestAdapter -> TaskService -> PostgreSQL réel.
 * Les tâches de test sont créées par TaskService (le CRUD REST n'est pas dans la tranche).
 */
final class RestTasksTest extends DbTestCase
{
    private Kernel $kernel;
    private TaskService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->kernel = Bootstrap::kernel($this->config);
        $this->svc = Bootstrap::taskService($this->config);
    }

    private function send(string $method, string $path, ?Kernel $kernel = null): Response
    {
        return ($kernel ?? $this->kernel)->handle(new Request($method, $path));
    }

    /** @return array<string,mixed> */
    private function json(Response $r): array
    {
        self::assertSame('application/json', $r->headers['Content-Type']);

        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }

    private function make(string $title): int
    {
        return $this->svc->create(['title' => $title, 'project' => 'Divers Pro'])['id'];
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }

    private function put(int $a, int $b): Response
    {
        return $this->send('PUT', "/api/v1/tasks/$a/required-actions/$b");
    }

    private function assertError(Response $r, int $status, string $code): array
    {
        self::assertSame($status, $r->status);
        $body = $this->json($r);
        self::assertSame(['error'], array_keys($body));
        self::assertSame($code, $body['error']['code']);
        self::assertIsString($body['error']['message']);

        return $body['error'];
    }

    public function testAddRequiredActionThenReadListsIt(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $r = $this->put($a, $b);
        self::assertSame(200, $r->status);
        $task = $this->json($r);
        self::assertSame($a, $task['id']);
        self::assertSame([['id' => $b, 'title' => 'B']], $task['required_actions']);

        $read = $this->send('GET', "/api/v1/tasks/$a");
        self::assertSame(200, $read->status);
        self::assertSame([['id' => $b, 'title' => 'B']], $this->json($read)['required_actions']);
        self::assertSame([], $this->json($this->send('GET', "/api/v1/tasks/$b"))['required_actions']);
    }

    public function testDirectCycleIsRefusedWith409AndNoLink(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        self::assertSame(200, $this->put($a, $b)->status);
        $error = $this->assertError($this->put($b, $a), 409, 'dependency_cycle');
        self::assertSame(['task_id' => $b, 'required_task_id' => $a], $error['details']);
        self::assertSame(1, $this->rows('task_dependencies'));
        self::assertSame([], $this->json($this->send('GET', "/api/v1/tasks/$b"))['required_actions']);
    }

    public function testIndirectCycleIsRefusedWith409AndNoLink(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $c = $this->make('C');
        self::assertSame(200, $this->put($a, $b)->status);
        self::assertSame(200, $this->put($b, $c)->status);
        $r = $this->put($c, $a);
        $this->assertError($r, 409, 'dependency_cycle');
        self::assertStringNotContainsString('->', $r->body); // pas de chemin du cycle (validé)
        self::assertSame(2, $this->rows('task_dependencies'));
    }

    public function testSelfDependencyIsRefusedWith422(): void
    {
        $a = $this->make('A');
        $error = $this->assertError($this->put($a, $a), 422, 'dependency_self');
        self::assertSame(['task_id' => $a], $error['details']);
        self::assertSame(0, $this->rows('task_dependencies'));
    }

    public function testUnknownTaskAndUnknownRequiredTaskGiveDistinctErrors(): void
    {
        $a = $this->make('A');
        $error = $this->assertError($this->put(999, $a), 404, 'task_not_found');
        self::assertSame(['id' => 999], $error['details']);
        $error = $this->assertError($this->put($a, 999), 422, 'required_task_not_found');
        self::assertSame(['required_task_id' => 999], $error['details']);
        // ordre des contrôles : la tâche inconnue passe avant l'auto-dépendance
        $this->assertError($this->put(999, 999), 404, 'task_not_found');
        self::assertSame(0, $this->rows('task_dependencies'));
    }

    public function testAddingTheSameLinkTwiceSucceedsWithoutDuplicate(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $first = $this->json($this->put($a, $b));
        $second = $this->put($a, $b);
        self::assertSame(200, $second->status);
        self::assertSame($first, $this->json($second));
        self::assertSame(1, $this->rows('task_dependencies'));
    }

    public function testDeleteIsRefusedWhileRequiredThenSucceedsAfterLinkRemoval(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $this->put($a, $b);

        $error = $this->assertError($this->send('DELETE', "/api/v1/tasks/$b"), 409, 'task_is_required');
        self::assertSame(['id' => $b, 'dependents' => [['id' => $a, 'title' => 'A']]], $error['details']);
        self::assertSame(200, $this->send('GET', "/api/v1/tasks/$b")->status);
        self::assertSame(1, $this->rows('task_dependencies'));

        self::assertSame(200, $this->send('DELETE', "/api/v1/tasks/$a/required-actions/$b")->status);
        $deleted = $this->send('DELETE', "/api/v1/tasks/$b");
        self::assertSame(204, $deleted->status);
        self::assertSame('', $deleted->body);
        $this->assertError($this->send('GET', "/api/v1/tasks/$b"), 404, 'task_not_found');
        $this->assertError($this->send('DELETE', "/api/v1/tasks/$b"), 404, 'task_not_found');
    }

    public function testRemoveExistingLinkThenAbsentLinkBothReturn200(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $this->put($a, $b);
        $removed = $this->send('DELETE', "/api/v1/tasks/$a/required-actions/$b");
        self::assertSame(200, $removed->status);
        self::assertSame([], $this->json($removed)['required_actions']);
        self::assertSame([], $this->json($this->send('GET', "/api/v1/tasks/$a"))['required_actions']);

        $again = $this->send('DELETE', "/api/v1/tasks/$a/required-actions/$b");
        self::assertSame(200, $again->status);
        self::assertSame([], $this->json($again)['required_actions']);
        // requiredId inexistant : toujours 200 (retrait idempotent)
        self::assertSame(200, $this->send('DELETE', "/api/v1/tasks/$a/required-actions/999")->status);
        $this->assertError($this->send('DELETE', "/api/v1/tasks/999/required-actions/$b"), 404, 'task_not_found');
    }

    public function testEffectiveLinkChangeAdvancesUpdatedAtButIdempotentCallsDoNot(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $old = "now() - interval '1 day'";
        $this->pdo->exec("UPDATE tasks SET updated_at = $old");
        $stamp = fn (): string => (string) $this->json($this->send('GET', "/api/v1/tasks/$a"))['updated_at'];
        $before = $stamp();
        $this->put($a, $b);
        $afterAdd = $stamp();
        self::assertNotSame($before, $afterAdd);
        $this->put($a, $b);
        self::assertSame($afterAdd, $stamp());
    }

    #[DataProvider('invalidIds')]
    public function testInvalidPathIdentifiersGive404NotFound(string $path): void
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->assertError($this->send($method, $path), 404, 'not_found');
        }
    }

    /** @return array<string,array{string}> */
    public static function invalidIds(): array
    {
        return [
            'zéro' => ['/api/v1/tasks/0'],
            'texte' => ['/api/v1/tasks/abc'],
            'dépassement 64 bits' => ['/api/v1/tasks/99999999999999999999'],
            'zéro initial' => ['/api/v1/tasks/01'],
            'négatif' => ['/api/v1/tasks/-1'],
            'décimal' => ['/api/v1/tasks/1.5'],
            'lien : tâche invalide' => ['/api/v1/tasks/abc/required-actions/1'],
            'lien : requise invalide' => ['/api/v1/tasks/1/required-actions/0'],
            'lien : requise hors entier' => ['/api/v1/tasks/1/required-actions/99999999999999999999'],
        ];
    }

    public function testUnknownSubPathsGive404(): void
    {
        foreach (['/api/v1/tasks/1/required-actions', '/api/v1/tasks/1/other/2', '/api/v1/tasks/1/required-actions/2/x'] as $path) {
            $this->assertError($this->send('GET', $path), 404, 'not_found');
        }
    }

    public function testWrongMethodsGive405WithAllowHeader(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        foreach (['POST', 'PUT'] as $method) {
            $r = $this->send($method, "/api/v1/tasks/$a");
            $this->assertError($r, 405, 'method_not_allowed');
            self::assertSame('GET, PATCH, DELETE', $r->headers['Allow']);
        }
        foreach (['GET', 'POST', 'PATCH'] as $method) {
            $r = $this->send($method, "/api/v1/tasks/$a/required-actions/$b");
            $this->assertError($r, 405, 'method_not_allowed');
            self::assertSame('PUT, DELETE', $r->headers['Allow']);
        }
        self::assertSame(0, $this->rows('task_dependencies'));
    }

    public function testUnexpectedPdoExceptionGives500WithoutLeak(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $e = new \PDOException('SECRET_MESSAGE relation "tasks" SELECT secret_col');
        $e->errorInfo = ['XX000', 7, 'SECRET_DRIVER_MESSAGE'];
        $pdo->method('prepare')->willThrowException($e);
        $pdo->method('beginTransaction')->willThrowException($e);

        $r = $this->send('GET', '/api/v1/tasks/1', $this->kernelWith($pdo));
        self::assertSame(['error' => ['code' => 'internal_error', 'message' => 'Erreur interne.']], $this->decodeError($r, 500));
        foreach (['XX000', 'SECRET', 'SELECT', 'tasks"', 'PDO'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
        $r = $this->send('PUT', '/api/v1/tasks/1/required-actions/2', $this->kernelWith($pdo));
        self::assertSame(500, $r->status);
        self::assertSame('internal_error', $this->json($r)['error']['code']);
    }

    public function testUnreachableDatabaseGives503(): void
    {
        $config = new Config('test', '127.0.0.1', 1, 'secret_db', 'secret_user', 'secret_pwd', []);
        $kernel = Bootstrap::kernel($config);
        foreach ([['GET', '/api/v1/tasks/1'], ['PUT', '/api/v1/tasks/1/required-actions/2'], ['DELETE', '/api/v1/tasks/1']] as [$method, $path]) {
            $r = $this->send($method, $path, $kernel);
            $this->assertError($r, 503, 'service_unavailable');
            foreach (['127.0.0.1', 'secret_db', 'secret_user', 'secret_pwd', 'SQLSTATE'] as $leak) {
                self::assertStringNotContainsString($leak, $r->body);
            }
        }
    }

    public function testHealthIsUnchanged(): void
    {
        $r = $this->send('GET', '/api/v1/health');
        self::assertSame(200, $r->status);
        self::assertSame(['status' => 'ok', 'database' => 'ok'], $this->json($r));
        $r = $this->send('POST', '/api/v1/health');
        $this->assertError($r, 405, 'method_not_allowed');
        self::assertSame('GET', $r->headers['Allow']);
    }

    /** @return array<string,mixed> */
    private function decodeError(Response $r, int $status): array
    {
        self::assertSame($status, $r->status);

        return $this->json($r);
    }

    private function kernelWith(\PDO $pdo): Kernel
    {
        $diagnostic = new Diagnostic(static fn (): \PDO => $pdo);
        $connect = static fn (): \PDO => $pdo;
        $service = \Actions\Tests\Support\Services::tasks($connect);
        $projects = \Actions\Tests\Support\Services::projects($connect);

        return new Kernel(
            new PortalAdapter($service, $projects, \Actions\Tests\Support\Services::overview($connect), new \Actions\Tests\Support\ArraySession(), ['127.0.0.1']),
            new RestAdapter($diagnostic, new TaskController($service), new \Actions\Rest\ProjectController($projects)),
            new McpAdapter($diagnostic, $service, $projects, [], ['127.0.0.1']),
        );
    }
}
