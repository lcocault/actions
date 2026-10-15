<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\InvalidTask;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Tests\Support\ArraySession;

/**
 * Champs « Projet » (désormais obligatoire et configurable, catégorie dérivée) et « Conclusions » : couche commune,
 * REST, MCP et portail contre PostgreSQL réel (base actions_test, remigrée dans setUp).
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

    public function testCreationWithProjectAndConclusions(): void
    {
        $min = $this->make();
        self::assertSame('Divers Pro', $min['project']);
        self::assertSame('Pro', $min['category']);
        self::assertNull($min['conclusions']);
        $full = $this->make(['project' => 'Jardin/Maison', 'conclusions' => "ligne 1\nligne 2"]);
        self::assertSame('Jardin/Maison', $full['project']);
        self::assertSame('Perso', $full['category'], 'catégorie dérivée du projet');
        self::assertSame("ligne 1\nligne 2", $full['conclusions']);
        self::assertSame($full, $this->svc->get($full['id']));
        self::assertSame('', $this->make(['conclusions' => ''])['conclusions'], 'chaîne vide conservée');
    }

    public function testEveryConfiguredProjectIsStoredWithItsCategory(): void
    {
        $expected = ['MS-HR' => 'Pro', 'Jardin/Maison' => 'Perso', 'OPF' => 'Perso', 'Erendil' => 'Perso', 'Divers Pro' => 'Pro', 'Divers Perso' => 'Perso'];
        foreach ($expected as $project => $category) {
            $t = $this->make(['project' => $project]);
            self::assertSame([$project, $category], [$t['project'], $t['category']]);
        }
    }

    public function testUpdateChangesProjectAndConclusionsAndLeavesUntouchedWhenAbsent(): void
    {
        $id = $this->make()['id'];
        self::assertSame('OPF', $this->svc->update($id, ['project' => 'OPF', 'conclusions' => 'a'])['project']);
        $t = $this->svc->update($id, ['project' => 'Erendil']);
        self::assertSame('Erendil', $t['project']);
        self::assertSame('a', $t['conclusions'], 'conclusions absentes : inchangées');
        $t = $this->svc->update($id, ['title' => 'Autre']);
        self::assertSame('Erendil', $t['project'], 'clé project absente : inchangé');
        self::assertSame('a', $t['conclusions']);
        $t = $this->svc->update($id, ['conclusions' => null]);
        self::assertSame('Erendil', $t['project']);
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
                self::assertStringContainsString('Projets disponibles : ', $e->getMessage());
                self::assertStringContainsString('OPF', $e->getMessage());
            }
        }
        try {
            $this->svc->update($id, ['project' => null]);
            self::fail('project_invalid attendu');
        } catch (InvalidTask $e) {
            self::assertSame('project_invalid', $e->code(), 'PC13 : project null refusé en modification');
        }
        foreach ([['project', 4, 'field_type_invalid'], ['conclusions', ['a'], 'field_type_invalid'], ['conclusions', "a\0b", 'text_invalid'], ['category', 'Pro', 'field_unknown']] as [$field, $value, $code]) {
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
        foreach ([['title' => 't', 'project' => 'x'], ['title' => 't'], ['title' => 't', 'project' => null], ['title' => 't', 'project' => 'OPF', 'category' => 'Perso']] as $data) {
            try {
                $this->svc->create($data);
                self::fail('InvalidTask attendue');
            } catch (InvalidTask) {
                self::assertSame($rows, $this->rows());
            }
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
        self::assertSame('Perso', $t['category'], 'PC9 : la catégorie suit le projet, même terminée');
        self::assertSame('Appris : X', $t['conclusions']);
    }

    public function testProjectFilterAloneAndAndedWithStatusAndCategory(): void
    {
        $a = $this->make(['title' => 'a', 'project' => 'MS-HR'])['id'];
        $b = $this->make(['title' => 'b', 'project' => 'OPF'])['id'];
        $c = $this->make(['title' => 'c', 'project' => 'Jardin/Maison'])['id'];
        $d = $this->make(['title' => 'd', 'project' => 'OPF'])['id'];
        $ids = fn (array $f): array => array_column($this->svc->list($f), 'id');
        self::assertSame([$b, $d], $ids(['project' => 'OPF']));
        self::assertSame([$c], $ids(['project' => 'Jardin/Maison']));
        self::assertSame([], $ids(['project' => 'Erendil']));
        self::assertSame([$b, $d], $ids(['project' => 'OPF', 'category' => 'Perso']));
        self::assertSame([], $ids(['project' => 'OPF', 'category' => 'Pro']));
        self::assertSame([$a], $ids(['category' => 'Pro']));
        $this->svc->changeStatus($b, 'in_progress');
        self::assertSame([$b], $ids(['project' => 'OPF', 'status' => 'in_progress']));
        self::assertSame([], $ids(['project' => 'Jardin/Maison', 'status' => 'in_progress']));
        foreach (['', 'opf', 'Autre'] as $bad) {
            try {
                $this->svc->list(['project' => $bad]);
                self::fail('project_invalid attendu');
            } catch (InvalidTask $e) {
                self::assertSame('project_invalid', $e->code());
                self::assertSame('project', $e->details()['field']);
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
        self::assertSame(['MS-HR', 'Pro'], [$next['project'], $next['category']]);
        self::assertNull($next['conclusions']);
        self::assertSame($next, $this->svc->get($next['id']));
        // La réouverture conserve projet et conclusions.
        $re = $this->svc->changeStatus($id, 'todo')['task'];
        self::assertSame('MS-HR', $re['project']);
        self::assertSame('à garder', $re['conclusions']);
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
        self::assertSame('Divers Pro', $this->svc->get($id)['project']);
    }

    // --- REST ---

    public function testRestPostPatchGetAndListCarryTheFields(): void
    {
        $r = $this->rest('POST', '/api/v1/tasks', ['title' => 'T', 'project' => 'Erendil', 'conclusions' => 'ok']);
        self::assertSame(201, $r->status, $r->body);
        $t = $this->json($r);
        self::assertSame(['Erendil', 'Perso', 'ok'], [$t['project'], $t['category'], $t['conclusions']]);
        $r = $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['project' => 'MS-HR']);
        self::assertSame(200, $r->status, $r->body);
        $t = $this->json($r);
        self::assertSame(['MS-HR', 'Pro', 'ok'], [$t['project'], $t['category'], $t['conclusions']]);
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
            [['project' => null], 'project_invalid', 'project'],
            [['project' => 3], 'field_type_invalid', 'project'],
            [['project' => ['OPF']], 'field_type_invalid', 'project'],
            [['category' => 'Pro'], 'field_unknown', 'category'],
            [['conclusions' => 3], 'field_type_invalid', 'conclusions'],
            [['conclusions' => "a\u{0000}b"], 'text_invalid', 'conclusions'],
        ] as [$body, $code, $field]) {
            $r = $this->rest('PATCH', "/api/v1/tasks/$id", $body);
            self::assertSame(422, $r->status, $r->body);
            $e = $this->json($r)['error'];
            self::assertSame($code, $e['code']);
            self::assertSame($field, $e['details']['field']);
            $r = $this->rest('POST', '/api/v1/tasks', $body + ['title' => 't', 'project' => 'OPF']);
            self::assertSame(422, $r->status, $r->body);
            self::assertSame($code, $this->json($r)['error']['code']);
        }
        $r = $this->rest('POST', '/api/v1/tasks', ['title' => 't']);
        self::assertSame([422, 'project_invalid'], [$r->status, $this->json($r)['error']['code']], 'projet obligatoire à la création');
        self::assertSame($before, $this->raw($id));
        self::assertSame(1, $this->rows());
    }

    public function testRestProjectFilter(): void
    {
        $a = $this->make(['title' => 'a', 'project' => 'Jardin/Maison'])['id'];
        $b = $this->make(['title' => 'b', 'project' => 'Jardin/Maison'])['id'];
        $this->make(['title' => 'c', 'project' => 'OPF']);
        $m = $this->make(['title' => 'd', 'project' => 'MS-HR'])['id'];
        $ids = fn (array $q): array => array_column($this->json($this->rest('GET', '/api/v1/tasks', null, $q)), 'id');
        self::assertSame([$a, $b], $ids(['project' => 'Jardin/Maison'])); // valeur décodée de Jardin%2FMaison
        self::assertSame([$a, $b], $ids(['project' => 'Jardin/Maison', 'category' => 'Perso']));
        self::assertSame([], $ids(['project' => 'Jardin/Maison', 'category' => 'Pro']), 'ET : catégorie du projet');
        self::assertSame([$m], $ids(['category' => 'Pro']));
        self::assertSame([], $ids(['project' => 'Erendil']));
        self::assertCount(4, $ids([]));
        self::assertCount(4, $ids(['inconnu' => 'x']), 'paramètre inconnu ignoré');
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
        self::assertSame('Pro', $body['next_occurrence']['category']);
        self::assertNull($body['next_occurrence']['conclusions']);
    }

    // --- MCP ---

    public function testMcpCreateUpdateListAndCompletion(): void
    {
        $created = json_decode($this->mcpText('create_task', ['title' => 'M', 'project' => 'OPF', 'conclusions' => 'c', 'due_date' => '2026-05-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']], false), true);
        self::assertSame(['OPF', 'Perso'], [$created['project'], $created['category']]);
        self::assertSame('c', $created['conclusions']);
        self::assertSame($created, $this->json($this->rest('GET', '/api/v1/tasks/' . $created['id'])), 'même Task que REST');
        $updated = json_decode($this->mcpText('update_task', ['id' => $created['id'], 'conclusions' => 'd'], false), true);
        self::assertSame('OPF', $updated['project']);
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
        foreach ([['project' => 'ms-hr'], ['project' => ''], ['project' => null], ['project' => 2], ['category' => 'Pro'], ['conclusions' => 2], ['conclusions' => "a\u{0000}"]] as $body) {
            $text = $this->mcpText('update_task', ['id' => $id] + $body, true);
            self::assertSame($this->rest('PATCH', "/api/v1/tasks/$id", $body)->body, $text);
            $text = $this->mcpText('create_task', $body + ['title' => 't', 'project' => 'OPF'], true);
            self::assertSame($this->rest('POST', '/api/v1/tasks', $body + ['title' => 't', 'project' => 'OPF'])->body, $text);
        }
        self::assertSame($this->rest('GET', '/api/v1/tasks', null, ['project' => 'x'])->body, $this->mcpText('list_tasks', ['project' => 'x'], true));
        self::assertSame(1, $this->rows());
        $this->mcpText('update_task', ['id' => $id, 'status' => 'done'], true); // status reste field_unknown
    }

    // --- Portail ---

    public function testPortalCreateEditAndDetail(): void
    {
        $r = $this->portalPost('/tasks', ['title' => 'P', 'project' => 'Jardin/Maison', 'conclusions' => "ligne <b>1</b>\nligne 2"]);
        self::assertSame(303, $r->status, $r->body);
        $t = $this->svc->list()[0];
        self::assertSame(['Jardin/Maison', 'Perso'], [$t['project'], $t['category']]);
        $detail = $this->portalGet('/tasks/' . $t['id'])->body;
        self::assertStringContainsString('Jardin/Maison', $detail);
        self::assertStringContainsString('Perso (selon le projet)', $detail);
        self::assertStringContainsString('ligne &lt;b&gt;1&lt;/b&gt;', $detail);
        self::assertStringNotContainsString('<b>1</b>', $detail);
        $edit = $this->portalGet('/tasks/' . $t['id'] . '/edit')->body;
        self::assertMatchesRegularExpression('/<option value="Jardin\/Maison" selected>/', $edit);
        self::assertStringContainsString('for="f-project"', $edit);
        self::assertStringContainsString('for="f-conclusions"', $edit);
        self::assertStringNotContainsString('f-category', $edit, 'plus de champ Catégorie dans le formulaire');
        // Champ conclusions vidé : effacé; le projet reste obligatoire.
        $r = $this->portalPost('/tasks/' . $t['id'], ['title' => 'P', 'project' => 'OPF', 'conclusions' => '']);
        self::assertSame(303, $r->status);
        $t = $this->svc->get($t['id']);
        self::assertSame('OPF', $t['project']);
        self::assertNull($t['conclusions']);
        self::assertStringContainsString('Aucune', $this->portalGet('/tasks/' . $t['id'])->body);
        $r = $this->portalPost('/tasks/' . $t['id'], ['title' => 'P', 'project' => '', 'conclusions' => '']);
        self::assertSame(422, $r->status, 'projet vide = project null : refusé');
        self::assertStringContainsString('id="e-project"', $r->body);
        self::assertSame('OPF', $this->svc->get($t['id'])['project']);
    }

    public function testPortalForgedProjectIsRefusedNearTheFieldAndKeepsInput(): void
    {
        $id = $this->make(['project' => 'OPF'])['id'];
        $before = $this->raw($id);
        $form = ['title' => 'Saisie conservée', 'project' => 'forgé', 'conclusions' => self::XSS];
        $r = $this->portalPost("/tasks/$id", $form);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Projet inconnu. Projets disponibles : ', $r->body);
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
        $r = $this->portalPost('/tasks', ['title' => 't', 'project' => 'OPF', 'conclusions' => "a\0b"]);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('id="e-conclusions"', $r->body);
    }

    public function testPortalIgnoresAForgedCategoryField(): void
    {
        // H7 : le portail ignore un champ `category` forgé (comme tout champ inconnu); la catégorie vient du projet.
        $r = $this->portalPost('/tasks', ['title' => 'Forgée', 'project' => 'OPF', 'category' => 'Pro']);
        self::assertSame(303, $r->status, $r->body);
        $t = $this->svc->list()[0];
        self::assertSame(['OPF', 'Perso'], [$t['project'], $t['category']]);
        $r = $this->portalPost('/tasks/' . $t['id'], ['title' => 'Forgée 2', 'project' => 'OPF', 'category' => 'Pro']);
        self::assertSame(303, $r->status, $r->body);
        self::assertSame('Perso', $this->svc->get($t['id'])['category']);
        // Le garde reste le cas d'usage : un appel direct refuse `category`.
        $this->expectException(InvalidTask::class);
        $this->svc->update($t['id'], ['category' => 'Pro']);
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
        $a = $this->make(['title' => 'Alpha', 'project' => 'OPF'])['id'];
        $this->make(['title' => 'Beta', 'project' => 'OPF']);
        $this->make(['title' => 'Gamma', 'project' => 'Erendil']);
        $this->make(['title' => 'Delta', 'project' => 'MS-HR']);
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
        $body = $this->portalGet('/tasks', ['project' => 'OPF', 'category' => 'Pro'])->body;
        self::assertStringNotContainsString('Alpha', $body, 'ET : OPF est Perso');
        $body = $this->portalGet('/tasks', ['category' => 'Pro'])->body;
        self::assertStringContainsString('Delta', $body);
        self::assertStringNotContainsString('Alpha', $body);
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
            $rest = $this->json($this->rest('POST', '/api/v1/tasks', ['title' => 't', 'project' => $value]))['error'];
            $portal = $this->portalPost('/tasks', ['title' => 't', 'project' => $value]);
            self::assertSame(422, $portal->status);
            self::assertStringContainsString(htmlspecialchars($rest['message'], ENT_QUOTES), $portal->body);
        }
    }
}
