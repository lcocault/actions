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
 * Champs « Projet » et « Conclusions » (tranche P) : couche commune, REST, MCP et portail
 * contre PostgreSQL réel (base actions_test, remigrée dans setUp).
 */
final class ProjectConclusionsTest extends DbTestCase
{
    private const HOST = '127.0.0.1:8080';
    private const XSS = '<script>alert(1)</script>';

    private Kernel $kernel;
    private ArraySession $session;
    private TaskService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, schema_migrations CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->migrate();
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
        return $this->svc->create($data + ['title' => 'T', 'category' => 'Pro']);
    }

    /** @return array<string,mixed> réponse JSON-RPC */
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

    /** @return array<string,mixed> ligne brute */
    private function raw(int $id): array
    {
        return $this->pdo->query("SELECT * FROM tasks WHERE id = $id")->fetch();
    }

    private function backdate(int $id): void
    {
        $this->pdo->exec("UPDATE tasks SET updated_at = '2000-01-01 00:00:00+00' WHERE id = $id");
    }

    private function updatedAt(int $id): string
    {
        return (string) $this->pdo->query("SELECT updated_at::text FROM tasks WHERE id = $id")->fetchColumn();
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn();
    }

    // --- Service ---

    public function testCreationWithoutTheFieldsGivesNullAndWithThemKeepsThem(): void
    {
        $min = $this->make();
        self::assertArrayHasKey('project', $min);
        self::assertArrayHasKey('conclusions', $min);
        self::assertNull($min['project']);
        self::assertNull($min['conclusions']);
        $full = $this->make(['project' => 'Jardin/Maison', 'conclusions' => "ligne 1\nligne 2"]);
        self::assertSame('Jardin/Maison', $full['project']);
        self::assertSame("ligne 1\nligne 2", $full['conclusions']);
        self::assertSame($full, $this->svc->get($full['id']));
        self::assertSame('', $this->make(['conclusions' => ''])['conclusions'], 'chaîne vide conservée');
    }

    public function testEveryProjectOfTheListIsStored(): void
    {
        foreach (TaskValidator::PROJECTS as $project) {
            self::assertSame($project, $this->make(['project' => $project])['project']);
        }
    }

    public function testUpdateSetsChangesClearsAndLeavesUntouchedWhenAbsent(): void
    {
        $id = $this->make()['id'];
        self::assertSame('OPF', $this->svc->update($id, ['project' => 'OPF', 'conclusions' => 'a'])['project']);
        $t = $this->svc->update($id, ['project' => 'Erendil']);
        self::assertSame('Erendil', $t['project']);
        self::assertSame('a', $t['conclusions'], 'conclusions absentes : inchangées');
        $t = $this->svc->update($id, ['title' => 'Autre']);
        self::assertSame('Erendil', $t['project']);
        self::assertSame('a', $t['conclusions']);
        $t = $this->svc->update($id, ['project' => null, 'conclusions' => null]);
        self::assertNull($t['project']);
        self::assertNull($t['conclusions']);
    }

    public function testIdenticalUpdateDoesNotWrite(): void
    {
        $id = $this->make(['project' => 'MS-HR', 'conclusions' => 'c'])['id'];
        $this->backdate($id);
        $before = $this->updatedAt($id);
        $this->svc->update($id, ['project' => 'MS-HR', 'conclusions' => 'c']);
        self::assertSame($before, $this->updatedAt($id));
        $this->svc->update($id, ['conclusions' => 'd']);
        self::assertNotSame($before, $this->updatedAt($id));
    }

    public function testInvalidValuesAreRefusedAndTaskIsUnchanged(): void
    {
        $id = $this->make(['project' => 'OPF', 'conclusions' => 'c'])['id'];
        $this->backdate($id);
        $before = $this->raw($id);
        foreach (['', 'opf', 'MS-HR ', 'Autre'] as $value) {
            try {
                $this->svc->update($id, ['project' => $value]);
                self::fail('project_invalid attendu');
            } catch (InvalidTask $e) {
                self::assertSame('project_invalid', $e->code());
                self::assertSame('project', $e->details()['field']);
            }
        }
        foreach ([['project', 4, 'field_type_invalid'], ['conclusions', ['a'], 'field_type_invalid'], ['conclusions', "a\0b", 'text_invalid']] as [$field, $value, $code]) {
            try {
                $this->svc->update($id, [$field => $value]);
                self::fail("$code attendu");
            } catch (InvalidTask $e) {
                self::assertSame($code, $e->code());
                self::assertSame($field, $e->details()['field']);
            }
        }
        self::assertSame($before, $this->raw($id));
        $rows = $this->rows();
        $this->expectException(InvalidTask::class);
        try {
            $this->svc->create(['title' => 't', 'category' => 'Pro', 'project' => 'x']);
        } finally {
            self::assertSame($rows, $this->rows());
        }
    }

    public function testFieldsStayEditableOnADoneTask(): void
    {
        $id = $this->make()['id'];
        $this->svc->changeStatus($id, 'in_progress');
        $this->svc->changeStatus($id, 'done');
        $t = $this->svc->update($id, ['project' => 'OPF', 'conclusions' => 'Appris : X']);
        self::assertSame('done', $t['status']);
        self::assertSame('OPF', $t['project']);
        self::assertSame('Appris : X', $t['conclusions']);
    }

    public function testProjectFilterAloneAndAndedWithStatusAndCategory(): void
    {
        $a = $this->make(['title' => 'a', 'project' => 'OPF', 'category' => 'Pro'])['id'];
        $b = $this->make(['title' => 'b', 'project' => 'OPF', 'category' => 'Perso'])['id'];
        $c = $this->make(['title' => 'c', 'project' => 'Jardin/Maison', 'category' => 'Perso'])['id'];
        $this->make(['title' => 'd']);
        $ids = fn (array $f): array => array_column($this->svc->list($f), 'id');
        self::assertSame([$a, $b], $ids(['project' => 'OPF']));
        self::assertSame([$c], $ids(['project' => 'Jardin/Maison']));
        self::assertSame([], $ids(['project' => 'Erendil']));
        self::assertSame([$b], $ids(['project' => 'OPF', 'category' => 'Perso']));
        $this->svc->changeStatus($a, 'in_progress');
        self::assertSame([$a], $ids(['project' => 'OPF', 'status' => 'in_progress']));
        self::assertSame([], $ids(['project' => 'Jardin/Maison', 'status' => 'in_progress']));
        foreach (['', 'opf'] as $bad) {
            try {
                $this->svc->list(['project' => $bad]);
                self::fail('project_invalid attendu');
            } catch (InvalidTask $e) {
                self::assertSame('project_invalid', $e->code());
            }
        }
    }

    public function testCompletingARecurringTaskCopiesProjectButNotConclusions(): void
    {
        $id = $this->make(['project' => 'MS-HR', 'conclusions' => 'à garder', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']])['id'];
        $this->svc->changeStatus($id, 'in_progress');
        $r = $this->svc->changeStatus($id, 'done');
        self::assertSame('MS-HR', $r['task']['project']);
        self::assertSame('à garder', $r['task']['conclusions']);
        $next = $r['next_occurrence'];
        self::assertSame('MS-HR', $next['project']);
        self::assertNull($next['conclusions']);
        self::assertSame($next, $this->svc->get($next['id']));
        // La réouverture conserve projet et conclusions.
        $re = $this->svc->changeStatus($id, 'todo')['task'];
        self::assertSame('MS-HR', $re['project']);
        self::assertSame('à garder', $re['conclusions']);
    }

    public function testCompletingARecurringTaskWithoutProjectCopiesNull(): void
    {
        $id = $this->make(['due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'day']])['id'];
        $this->svc->changeStatus($id, 'in_progress');
        $next = $this->svc->changeStatus($id, 'done')['next_occurrence'];
        self::assertNull($next['project']);
        self::assertNull($next['conclusions']);
    }

    public function testCompletingANonRecurringTaskCreatesNothing(): void
    {
        $id = $this->make(['project' => 'OPF'])['id'];
        $this->svc->changeStatus($id, 'in_progress');
        self::assertNull($this->svc->changeStatus($id, 'done')['next_occurrence']);
        self::assertSame(1, $this->rows());
    }

    public function testStatusWithProjectIsNotAlone(): void
    {
        $id = $this->make()['id'];
        try {
            $this->svc->patch($id, ['status' => 'in_progress', 'project' => 'OPF']);
            self::fail('status_not_alone attendu');
        } catch (InvalidTask $e) {
            self::assertSame('status_not_alone', $e->code());
        }
        self::assertNull($this->svc->get($id)['project']);
    }

    // --- REST ---

    public function testRestPostPatchGetAndListCarryTheFields(): void
    {
        $r = $this->rest('POST', '/api/v1/tasks', ['title' => 'T', 'category' => 'Pro', 'project' => 'Erendil', 'conclusions' => 'ok']);
        self::assertSame(201, $r->status, $r->body);
        $t = $this->json($r);
        self::assertSame('Erendil', $t['project']);
        self::assertSame('ok', $t['conclusions']);
        $r = $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['project' => null]);
        self::assertSame(200, $r->status);
        $t = $this->json($r);
        self::assertNull($t['project']);
        self::assertSame('ok', $t['conclusions']);
        self::assertSame($t, $this->json($this->rest('GET', '/api/v1/tasks/' . $t['id'])));
        $list = $this->json($this->rest('GET', '/api/v1/tasks'));
        self::assertArrayHasKey('project', $list[0]);
        self::assertArrayHasKey('conclusions', $list[0]);
    }

    public function testRestRefusals(): void
    {
        $id = $this->make(['project' => 'OPF'])['id'];
        $before = $this->raw($id);
        foreach ([
            [['project' => 'opf'], 'project_invalid', 'project'],
            [['project' => ''], 'project_invalid', 'project'],
            [['project' => 3], 'field_type_invalid', 'project'],
            [['project' => ['OPF']], 'field_type_invalid', 'project'],
            [['conclusions' => 3], 'field_type_invalid', 'conclusions'],
            [['conclusions' => "a\u{0000}b"], 'text_invalid', 'conclusions'],
        ] as [$body, $code, $field]) {
            $r = $this->rest('PATCH', "/api/v1/tasks/$id", $body);
            self::assertSame(422, $r->status, $r->body);
            $e = $this->json($r)['error'];
            self::assertSame($code, $e['code']);
            self::assertSame($field, $e['details']['field']);
            $r = $this->rest('POST', '/api/v1/tasks', ['title' => 't', 'category' => 'Pro'] + $body);
            self::assertSame(422, $r->status, $r->body);
            self::assertSame($code, $this->json($r)['error']['code']);
        }
        self::assertSame($before, $this->raw($id));
        self::assertSame(1, $this->rows());
    }

    public function testRestProjectFilter(): void
    {
        $a = $this->make(['title' => 'a', 'project' => 'Jardin/Maison', 'category' => 'Pro'])['id'];
        $b = $this->make(['title' => 'b', 'project' => 'Jardin/Maison', 'category' => 'Perso'])['id'];
        $this->make(['title' => 'c', 'project' => 'OPF']);
        $ids = fn (array $q): array => array_column($this->json($this->rest('GET', '/api/v1/tasks', null, $q)), 'id');
        self::assertSame([$a, $b], $ids(['project' => 'Jardin/Maison'])); // valeur décodée de Jardin%2FMaison
        self::assertSame([$b], $ids(['project' => 'Jardin/Maison', 'category' => 'Perso']));
        self::assertSame([], $ids(['project' => 'Erendil']));
        self::assertCount(3, $ids([]));
        self::assertCount(3, $ids(['inconnu' => 'x']), 'paramètre inconnu ignoré');
        foreach ([['project' => '', 'project_invalid'], ['project' => 'ms-hr', 'project_invalid'], ['project' => ['x'], 'field_type_invalid']] as $q) {
            $code = array_pop($q);
            $r = $this->rest('GET', '/api/v1/tasks', null, $q);
            self::assertSame(422, $r->status, $r->body);
            $e = $this->json($r)['error'];
            self::assertSame($code, $e['code']);
            self::assertSame('project', $e['details']['field']);
        }
    }

    public function testRestCompletionByPatchCopiesProject(): void
    {
        $id = $this->make(['project' => 'MS-HR', 'conclusions' => 'x', 'due_date' => '2026-03-01', 'recurrence' => ['interval' => 1, 'unit' => 'week']])['id'];
        $this->rest('PATCH', "/api/v1/tasks/$id", ['status' => 'in_progress']);
        $body = $this->json($this->rest('PATCH', "/api/v1/tasks/$id", ['status' => 'done']));
        self::assertSame('MS-HR', $body['project']);
        self::assertSame('x', $body['conclusions']);
        self::assertSame('MS-HR', $body['next_occurrence']['project']);
        self::assertNull($body['next_occurrence']['conclusions']);
    }

    // --- MCP ---

    public function testMcpToolsDeclareTheFields(): void
    {
        $r = $this->kernel->handle(new Request('POST', '/mcp', ['host' => self::HOST, 'content-type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        $tools = [];
        foreach ($this->json($r)['result']['tools'] as $t) {
            $tools[$t['name']] = $t['inputSchema'];
        }
        foreach (['create_task', 'update_task'] as $name) {
            $p = $tools[$name]['properties'];
            self::assertSame([...TaskValidator::PROJECTS, null], $p['project']['enum']);
            self::assertSame(['string', 'null'], $p['project']['type']);
            self::assertSame(['string', 'null'], $p['conclusions']['type']);
            self::assertNotContains('project', $tools[$name]['required']);
            self::assertNotContains('conclusions', $tools[$name]['required']);
        }
        self::assertSame(TaskValidator::PROJECTS, $tools['list_tasks']['properties']['project']['enum']);
        self::assertSame(['id', 'status'], array_keys($tools['change_task_status']['properties']));
    }

    public function testMcpCreateUpdateListAndCompletion(): void
    {
        $created = json_decode($this->mcpText('create_task', ['title' => 'M', 'category' => 'Perso', 'project' => 'OPF', 'conclusions' => 'c', 'due_date' => '2026-05-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']], false), true);
        self::assertSame('OPF', $created['project']);
        self::assertSame('c', $created['conclusions']);
        self::assertSame($created, $this->json($this->rest('GET', '/api/v1/tasks/' . $created['id'])), 'même Task que REST');
        $updated = json_decode($this->mcpText('update_task', ['id' => $created['id'], 'project' => null, 'conclusions' => 'd'], false), true);
        self::assertNull($updated['project']);
        self::assertSame('d', $updated['conclusions']);
        $this->mcpText('update_task', ['id' => $created['id'], 'project' => 'Erendil'], false);
        $this->make(['title' => 'autre', 'project' => 'OPF']);
        $list = json_decode($this->mcpText('list_tasks', ['project' => 'Erendil'], false), true);
        self::assertSame([$created['id']], array_column($list, 'id'));
        $this->mcpText('change_task_status', ['id' => $created['id'], 'status' => 'in_progress'], false);
        $done = json_decode($this->mcpText('change_task_status', ['id' => $created['id'], 'status' => 'done'], false), true);
        self::assertSame('Erendil', $done['next_occurrence']['project']);
        self::assertNull($done['next_occurrence']['conclusions']);
        self::assertSame('d', $done['conclusions']);
    }

    public function testMcpErrorsAreByteIdenticalToRest(): void
    {
        $id = $this->make()['id'];
        foreach ([['project' => 'ms-hr'], ['project' => ''], ['project' => 2], ['conclusions' => 2], ['conclusions' => "a\u{0000}"]] as $body) {
            $text = $this->mcpText('update_task', ['id' => $id] + $body, true);
            self::assertSame($this->rest('PATCH', "/api/v1/tasks/$id", $body)->body, $text);
            $text = $this->mcpText('create_task', ['title' => 't', 'category' => 'Pro'] + $body, true);
            self::assertSame($this->rest('POST', '/api/v1/tasks', ['title' => 't', 'category' => 'Pro'] + $body)->body, $text);
        }
        self::assertSame($this->rest('GET', '/api/v1/tasks', null, ['project' => 'x'])->body, $this->mcpText('list_tasks', ['project' => 'x'], true));
        self::assertSame(1, $this->rows());
        $this->mcpText('update_task', ['id' => $id, 'status' => 'done'], true); // status reste field_unknown
    }

    // --- Portail ---

    public function testPortalCreateEditAndDetail(): void
    {
        $r = $this->portalPost('/tasks', ['title' => 'P', 'category' => 'Pro', 'project' => 'Jardin/Maison', 'conclusions' => "ligne <b>1</b>\nligne 2"]);
        self::assertSame(303, $r->status, $r->body);
        $t = $this->svc->list()[0];
        self::assertSame('Jardin/Maison', $t['project']);
        $detail = $this->portalGet('/tasks/' . $t['id'])->body;
        self::assertStringContainsString('Jardin/Maison', $detail);
        self::assertStringContainsString('ligne &lt;b&gt;1&lt;/b&gt;', $detail);
        self::assertStringNotContainsString('<b>1</b>', $detail);
        $edit = $this->portalGet('/tasks/' . $t['id'] . '/edit')->body;
        self::assertMatchesRegularExpression('/<option value="Jardin\/Maison" selected>/', $edit);
        self::assertStringContainsString('for="f-project"', $edit);
        self::assertStringContainsString('for="f-conclusions"', $edit);
        // Option vide et champ vidé effacent.
        $r = $this->portalPost('/tasks/' . $t['id'], ['title' => 'P', 'category' => 'Pro', 'project' => '', 'conclusions' => '']);
        self::assertSame(303, $r->status);
        $t = $this->svc->get($t['id']);
        self::assertNull($t['project']);
        self::assertNull($t['conclusions']);
        $detail = $this->portalGet('/tasks/' . $t['id'])->body;
        self::assertStringContainsString('Aucun', $detail);
        self::assertStringContainsString('Aucune', $detail);
    }

    public function testPortalForgedProjectIsRefusedNearTheFieldAndKeepsInput(): void
    {
        $id = $this->make(['project' => 'OPF'])['id'];
        $before = $this->raw($id);
        $form = ['title' => 'Saisie conservée', 'category' => 'Pro', 'project' => 'forgé', 'conclusions' => self::XSS];
        $r = $this->portalPost("/tasks/$id", $form);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Le projet doit être MS-HR, Jardin/Maison, OPF ou Erendil.', $r->body);
        self::assertStringContainsString('href="#f-project"', $r->body);
        self::assertStringContainsString('id="e-project"', $r->body);
        self::assertStringContainsString('Saisie conservée', $r->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $r->body);
        self::assertStringNotContainsString(self::XSS, $r->body);
        self::assertSame($before, $this->raw($id));
        $r = $this->portalPost('/tasks', $form);
        self::assertSame(422, $r->status);
        self::assertSame(1, $this->rows());
        // Texte invalide : message près du champ conclusions.
        $r = $this->portalPost('/tasks', ['title' => 't', 'category' => 'Pro', 'conclusions' => "a\0b"]);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('id="e-conclusions"', $r->body);
    }

    public function testPortalXssInConclusionsIsEscapedInDetail(): void
    {
        $id = $this->make(['conclusions' => self::XSS])['id'];
        $body = $this->portalGet("/tasks/$id")->body;
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString(self::XSS, $body);
    }

    public function testPortalListColumnAndExactFilterAndedWithOthers(): void
    {
        $a = $this->make(['title' => 'Alpha', 'project' => 'OPF', 'category' => 'Pro'])['id'];
        $this->make(['title' => 'Beta', 'project' => 'OPF', 'category' => 'Perso']);
        $this->make(['title' => 'Gamma', 'project' => 'Erendil']);
        $this->make(['title' => 'Delta']);
        $full = $this->portalGet('/tasks')->body;
        self::assertStringContainsString('<th scope="col">Projet</th>', $full);
        self::assertStringContainsString('for="f-project"', $full);
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $title) {
            self::assertStringContainsString($title, $full);
        }
        $body = $this->portalGet('/tasks', ['project' => 'OPF'])->body;
        self::assertStringContainsString('Alpha', $body);
        self::assertStringContainsString('Beta', $body);
        self::assertStringNotContainsString('Gamma', $body);
        self::assertStringNotContainsString('Delta', $body);
        self::assertStringContainsString('projet <strong>OPF</strong>', $body);
        $body = $this->portalGet('/tasks', ['project' => 'OPF', 'category' => 'Perso'])->body;
        self::assertStringNotContainsString('Alpha', $body);
        self::assertStringContainsString('Beta', $body);
        $this->svc->changeStatus($a, 'in_progress');
        $this->svc->changeStatus($a, 'done');
        $body = $this->portalGet('/tasks', ['project' => 'OPF', 'hide_done' => '1'])->body;
        self::assertStringNotContainsString('Alpha', $body);
        self::assertStringContainsString('Beta', $body);
        // « Tous » (valeur vide) = filtre absent.
        self::assertStringContainsString('Delta', $this->portalGet('/tasks', ['project' => ''])->body);
        // Valeur forgée.
        foreach (['opf', 'Autre', ['x']] as $bad) {
            $r = $this->portalGet('/tasks', ['project' => $bad]);
            self::assertSame(422, $r->status);
            self::assertStringContainsString('Filtre invalide', $r->body);
        }
    }

    public function testPortalCompletionShowsTheCopiedProjectOnTheNewOccurrence(): void
    {
        $id = $this->make(['project' => 'MS-HR', 'conclusions' => 'x', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']])['id'];
        $this->svc->changeStatus($id, 'in_progress');
        self::assertSame(303, $this->portalPost("/tasks/$id/status", ['status' => 'done'])->status);
        $next = array_values(array_filter($this->svc->list(), static fn (array $t): bool => $t['id'] !== $id))[0];
        self::assertSame('MS-HR', $next['project']);
        self::assertNull($next['conclusions']);
        self::assertStringContainsString('MS-HR', $this->portalGet('/tasks/' . $next['id'])->body);
    }

    public function testPortalAndRestAgreeOnProjectValidation(): void
    {
        foreach (['ms-hr', 'Autre'] as $value) {
            $rest = $this->json($this->rest('POST', '/api/v1/tasks', ['title' => 't', 'category' => 'Pro', 'project' => $value]))['error'];
            $portal = $this->portalPost('/tasks', ['title' => 't', 'category' => 'Pro', 'project' => $value]);
            self::assertSame(422, $portal->status);
            self::assertStringContainsString(htmlspecialchars($rest['message'], ENT_QUOTES), $portal->body);
        }
    }
}
