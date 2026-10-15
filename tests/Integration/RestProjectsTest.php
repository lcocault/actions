<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Config;

/** REST des projets (`/api/v1/projects`) : Kernel -> RestAdapter -> ProjectController -> ProjectService -> PostgreSQL réel. */
final class RestProjectsTest extends DbTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->kernel = Bootstrap::kernel($this->config);
    }

    private function send(string $method, string $path, mixed $body = null, ?Kernel $kernel = null): Response
    {
        $raw = is_string($body) ? $body : ($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));

        return ($kernel ?? $this->kernel)->handle(new Request($method, $path, [], $raw));
    }

    /** @return mixed */
    private function json(Response $r): mixed
    {
        self::assertSame('application/json', $r->headers['Content-Type']);

        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> l'erreur décodée, après contrôle du statut, du code et du champ */
    private function assertError(Response $r, int $status, string $code, ?string $field = null): array
    {
        self::assertSame($status, $r->status, $r->body);
        $error = $this->json($r)['error'];
        self::assertSame($code, $error['code'], $r->body);
        if ($field !== null) {
            self::assertSame($field, $error['details']['field'], $r->body);
        }

        return $error;
    }

    private function create(string $name, string $category): array
    {
        $r = $this->send('POST', '/api/v1/projects', ['name' => $name, 'category' => $category]);
        self::assertSame(201, $r->status, $r->body);

        return $this->json($r);
    }

    private function task(string $project, array $extra = []): array
    {
        $r = $this->send('POST', '/api/v1/tasks', ['title' => 't', 'project' => $project] + $extra);
        self::assertSame(201, $r->status, $r->body);

        return $this->json($r);
    }

    // --- Lecture ---

    public function testListReturnsTheSixProjects(): void
    {
        $r = $this->send('GET', '/api/v1/projects');
        self::assertSame(200, $r->status);
        $list = $this->json($r);
        self::assertSame(['Divers Perso', 'Divers Pro', 'Erendil', 'Jardin/Maison', 'MS-HR', 'OPF'], array_column($list, 'name'));
        self::assertSame(['id', 'name', 'category', 'task_count'], array_keys($list[0]));
        self::assertIsInt($list[0]['id']);
        self::assertIsInt($list[0]['task_count']);
    }

    public function testGetOneAndNotFoundAndBadIds(): void
    {
        $id = $this->projectId('OPF');
        $r = $this->send('GET', "/api/v1/projects/$id");
        self::assertSame(200, $r->status);
        self::assertSame(['id' => $id, 'name' => 'OPF', 'category' => 'Perso', 'task_count' => 0], $this->json($r));
        $this->assertError($this->send('GET', '/api/v1/projects/999999'), 404, 'project_not_found');
        foreach (['0', '-1', 'abc', '01', '1.5', '99999999999999999999'] as $bad) {
            $this->assertError($this->send('GET', "/api/v1/projects/$bad"), 404, 'not_found');
        }
    }

    public function testMethodsNotAllowedCarryAllow(): void
    {
        foreach ([['PUT', '/api/v1/projects', 'GET, POST'], ['DELETE', '/api/v1/projects', 'GET, POST'], ['PUT', '/api/v1/projects/1', 'GET, PATCH, DELETE'], ['POST', '/api/v1/projects/1', 'GET, PATCH, DELETE']] as [$m, $path, $allow]) {
            $r = $this->send($m, $path);
            self::assertSame(405, $r->status, "$m $path");
            self::assertSame($allow, $r->headers['Allow']);
            self::assertSame('method_not_allowed', $this->json($r)['error']['code']);
        }
    }

    // --- Création ---

    public function testPostCreatesWithLocationAndRealProjectIsUsable(): void
    {
        $r = $this->send('POST', '/api/v1/projects', ['name' => 'Nouveau é', 'category' => 'Pro']);
        self::assertSame(201, $r->status, $r->body);
        $p = $this->json($r);
        self::assertSame(['Nouveau é', 'Pro', 0], [$p['name'], $p['category'], $p['task_count']]);
        self::assertSame('/api/v1/projects/' . $p['id'], $r->headers['Location']);
        self::assertSame($p, $this->json($this->send('GET', $r->headers['Location'])));
        self::assertSame('Pro', $this->task('Nouveau é')['category']);
    }

    public function testPostRefusals(): void
    {
        foreach ([
            [['name' => '', 'category' => 'Pro'], 422, 'project_name_blank', 'name'],
            [['name' => "  \t", 'category' => 'Pro'], 422, 'project_name_blank', 'name'],
            [['category' => 'Pro'], 422, 'project_name_blank', 'name'],
            [['name' => str_repeat('a', 256), 'category' => 'Pro'], 422, 'project_name_too_long', 'name'],
            [['name' => "a\u{0000}b", 'category' => 'Pro'], 422, 'text_invalid', 'name'],
            [['name' => 'X', 'category' => 'pro'], 422, 'category_invalid', 'category'],
            [['name' => 'X'], 422, 'category_invalid', 'category'],
            [['name' => 3, 'category' => 'Pro'], 422, 'field_type_invalid', 'name'],
            [['name' => 'X', 'category' => null], 422, 'field_type_invalid', 'category'],
            [['name' => 'X', 'category' => 'Pro', 'id' => 5], 422, 'field_unknown', 'id'],
            [['name' => 'X', 'category' => 'Pro', 'task_count' => 5], 422, 'field_unknown', 'task_count'],
            [['name' => 'opf', 'category' => 'Pro'], 409, 'project_name_taken', 'name'],
            [['name' => 'OPF', 'category' => 'Pro'], 409, 'project_name_taken', 'name'],
        ] as [$body, $status, $code, $field]) {
            $this->assertError($this->send('POST', '/api/v1/projects', $body), $status, $code, $field);
        }
        self::assertCount(6, $this->json($this->send('GET', '/api/v1/projects')));
    }

    public function testPostWithInvalidJsonBodyIs400(): void
    {
        foreach (['', '{', '[]', '"x"', '12', 'null', '[{"name":"X"}]'] as $raw) {
            $this->assertError($this->send('POST', '/api/v1/projects', $raw), 400, 'invalid_json');
            $this->assertError($this->send('PATCH', '/api/v1/projects/' . $this->projectId('OPF'), $raw), 400, 'invalid_json');
        }
        self::assertCount(6, $this->json($this->send('GET', '/api/v1/projects')));
    }

    // --- Modification ---

    public function testPatchRenameAndRecategorizeReportsTouchedTasks(): void
    {
        $id = $this->projectId('OPF');
        $a = $this->task('OPF');
        $b = $this->task('OPF');
        $this->send('PATCH', "/api/v1/tasks/{$b['id']}", ['status' => 'in_progress']);
        $this->send('PATCH', "/api/v1/tasks/{$b['id']}", ['status' => 'done']);

        $r = $this->send('PATCH', "/api/v1/projects/$id", ['category' => 'Pro']);
        self::assertSame(200, $r->status, $r->body);
        self::assertSame(['id' => $id, 'name' => 'OPF', 'category' => 'Pro', 'task_count' => 2, 'tasks_recategorized' => 2], $this->json($r));
        self::assertSame('Pro', $this->json($this->send('GET', "/api/v1/tasks/{$a['id']}"))['category']);
        self::assertSame('Pro', $this->json($this->send('GET', "/api/v1/tasks/{$b['id']}"))['category'], 'tâche terminée comprise');

        $r = $this->send('PATCH', "/api/v1/projects/$id", ['name' => 'Perso renommé', 'category' => 'Pro']);
        self::assertSame(['Perso renommé', 'Pro', 0], [$this->json($r)['name'], $this->json($r)['category'], $this->json($r)['tasks_recategorized']]);
        self::assertSame('Perso renommé', $this->json($this->send('GET', "/api/v1/tasks/{$a['id']}"))['project']);
        $r = $this->send('PATCH', "/api/v1/projects/$id", '{}');
        self::assertSame(['Perso renommé', 0], [$this->json($r)['name'], $this->json($r)['tasks_recategorized']], 'objet vide : inchangé');
    }

    public function testPatchRefusals(): void
    {
        $id = $this->projectId('OPF');
        $this->assertError($this->send('PATCH', '/api/v1/projects/999999', ['name' => '']), 404, 'project_not_found'); // 404 avant 422
        foreach ([
            [['name' => null], 422, 'field_type_invalid', 'name'],
            [['category' => null], 422, 'field_type_invalid', 'category'],
            [['name' => ' '], 422, 'project_name_blank', 'name'],
            [['category' => 'x'], 422, 'category_invalid', 'category'],
            [['name' => str_repeat('é', 256)], 422, 'project_name_too_long', 'name'],
            [['id' => 9], 422, 'field_unknown', 'id'],
            [['name' => 'erendil'], 409, 'project_name_taken', 'name'],
        ] as [$body, $status, $code, $field]) {
            $this->assertError($this->send('PATCH', "/api/v1/projects/$id", $body), $status, $code, $field);
        }
        self::assertSame('OPF', $this->json($this->send('GET', "/api/v1/projects/$id"))['name']);
    }

    // --- Suppression ---

    public function testDeleteUnusedThenUsedIs409WithCount(): void
    {
        $this->task('OPF');
        $this->task('OPF');
        $opf = $this->projectId('OPF');
        $e = $this->assertError($this->send('DELETE', "/api/v1/projects/$opf"), 409, 'project_in_use');
        self::assertSame(['id' => $opf, 'task_count' => 2], $e['details']);
        self::assertSame(200, $this->send('GET', "/api/v1/projects/$opf")->status);

        $erendil = $this->projectId('Erendil');
        $r = $this->send('DELETE', '/api/v1/projects/' . $erendil);
        self::assertSame(204, $r->status);
        self::assertSame('', $r->body);
        $this->assertError($this->send('DELETE', '/api/v1/projects/999999'), 404, 'project_not_found');
        $this->assertError($this->send('DELETE', '/api/v1/projects/' . $erendil), 404, 'project_not_found', null); // déjà supprimé
        self::assertCount(5, $this->json($this->send('GET', '/api/v1/projects')));
    }

    // --- Tâches : contrat modifié ---

    public function testTaskContractAfterProjectsCategoryRefusedProjectRequired(): void
    {
        $this->assertError($this->send('POST', '/api/v1/tasks', ['title' => 't', 'project' => 'OPF', 'category' => 'Perso']), 422, 'field_unknown', 'category');
        $this->assertError($this->send('POST', '/api/v1/tasks', ['title' => 't', 'category' => 'Perso']), 422, 'field_unknown', 'category');
        $this->assertError($this->send('POST', '/api/v1/tasks', ['title' => 't']), 422, 'project_invalid', 'project');
        $this->assertError($this->send('POST', '/api/v1/tasks', ['title' => 't', 'project' => null]), 422, 'project_invalid', 'project');
        $this->assertError($this->send('POST', '/api/v1/tasks', ['title' => 't', 'project' => 'Inconnu']), 422, 'project_invalid', 'project');
        $t = $this->task('Jardin/Maison');
        $this->assertError($this->send('PATCH', "/api/v1/tasks/{$t['id']}", ['category' => 'Pro']), 422, 'field_unknown', 'category');
        $this->assertError($this->send('PATCH', "/api/v1/tasks/{$t['id']}", ['project' => null]), 422, 'project_invalid', 'project');
        $r = $this->send('PATCH', "/api/v1/tasks/{$t['id']}", ['project' => 'MS-HR']);
        self::assertSame(['MS-HR', 'Pro'], [$this->json($r)['project'], $this->json($r)['category']]);
        $r = $this->send('PATCH', "/api/v1/tasks/{$t['id']}", ['title' => 'seul']);
        self::assertSame('MS-HR', $this->json($r)['project'], 'clé project absente : inchangé');
    }

    public function testLocationHonoursTheBasePath(): void
    {
        $config = new Config('test', $this->config->dbHost, $this->config->dbPort, $this->config->dbName, $this->config->dbUser, $this->config->dbPassword, [], appBasePath: '/actions');
        $kernel = Bootstrap::kernel($config);
        $r = $kernel->handle(new Request('POST', '/actions/api/v1/projects', [], '{"name":"Préfixé","category":"Pro"}'));
        self::assertSame(201, $r->status, $r->body);
        self::assertSame('/actions/api/v1/projects/' . $this->json($r)['id'], $r->headers['Location']);
    }

    public function testDatabaseOutageGives503WithoutLeak(): void
    {
        $config = new Config('test', '127.0.0.1', 1, 'secret_db', 'secret_user', 'secret_pwd', []);
        foreach ([['GET', '/api/v1/projects', null], ['POST', '/api/v1/projects', '{"name":"X","category":"Pro"}'], ['DELETE', '/api/v1/projects/1', null]] as [$m, $path, $body]) {
            $r = $this->send($m, $path, $body, Bootstrap::kernel($config));
            self::assertSame(503, $r->status, "$m $path");
            self::assertSame('service_unavailable', $this->json($r)['error']['code']);
            foreach (['secret_db', 'secret_user', 'secret_pwd', 'SQLSTATE', '127.0.0.1'] as $leak) {
                self::assertStringNotContainsString($leak, $r->body);
            }
        }
    }
}
