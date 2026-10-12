<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Application\TaskStatus;
use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Migrator;
use Actions\Portal\PortalAdapter;
use Actions\Tests\Support\ArraySession;

/** Portail B2 (actions requises, changement d'état, réouverture) : Kernel -> PortalAdapter -> TaskService -> PostgreSQL réel. */
final class PortalTasksActionsTest extends DbTestCase
{
    private const HOST = '127.0.0.1:8080';
    private const XSS = '<script>alert(1)</script>';
    private const XSS_ESCAPED = '&lt;script&gt;alert(1)&lt;/script&gt;';

    private Kernel $kernel;
    private ArraySession $session;
    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, schema_migrations CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->migrate();
        $this->session = new ArraySession();
        $this->kernel = Bootstrap::kernel($this->config, $this->session);
        $this->service = Bootstrap::taskService($this->config);
    }

    private function get(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, ['host' => self::HOST]));
    }

    /** @param array<string,mixed> $form @param array<string,string> $headers */
    private function post(string $path, array $form = [], array $headers = [], bool $withToken = true): Response
    {
        if ($withToken) {
            $form += ['_csrf' => $this->session->csrfToken()];
        }

        return $this->kernel->handle(new Request('POST', $path, ['host' => self::HOST] + $headers, '', [], $form));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function seed(string $title = 'Tâche', array $data = []): array
    {
        return $this->service->create($data + ['title' => $title, 'category' => 'Pro']);
    }

    private function snapshot(): string
    {
        return (string) json_encode([
            $this->pdo->query('SELECT * FROM tasks ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
            $this->pdo->query('SELECT * FROM task_dependencies ORDER BY task_id, required_task_id')->fetchAll(\PDO::FETCH_ASSOC),
        ]);
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn();
    }

    private function links(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM task_dependencies')->fetchColumn();
    }

    private function assertRedirect(Response $r, string $location): void
    {
        self::assertSame(303, $r->status, $r->body);
        self::assertSame($location, $r->headers['Location']);
        self::assertSame('', $r->body);
    }

    private function assertHtml(Response $r, int $status): void
    {
        self::assertSame($status, $r->status, $r->body);
        self::assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
    }

    private function addLink(int $id, mixed $requiredId): Response
    {
        return $this->post('/tasks/' . $id . '/required-actions', ['required_id' => (string) $requiredId]);
    }

    private function changeTo(int $id, string $status): Response
    {
        return $this->post('/tasks/' . $id . '/status', ['status' => $status]);
    }

    /** Page de détail suivie après une redirection (le flash est consommé). */
    private function followed(Response $r, int $id): string
    {
        $this->assertRedirect($r, '/tasks/' . $id);

        return html_entity_decode($this->get('/tasks/' . $id)->body, ENT_QUOTES, 'UTF-8');
    }

    // ----- Actions requises -----

    public function testAddRequiredActionShowsItInDetail(): void
    {
        $req = $this->seed('Prérequis');
        $main = $this->seed('Principale');
        $body = $this->followed($this->addLink($main['id'], $req['id']), $main['id']);
        self::assertStringContainsString('Action requise ajoutée.', $body);
        self::assertStringContainsString('<a href="/tasks/' . $req['id'] . '">Prérequis</a>', $body);
        self::assertSame([['id' => $req['id'], 'title' => 'Prérequis']], $this->service->get($main['id'])['required_actions']);
    }

    public function testCandidateListHasAllTasksWithStateAndMarksItself(): void
    {
        $a = $this->seed('Alpha');
        $b = $this->seed('Bêta');
        $this->service->changeStatus($b['id'], 'in_progress');
        $body = $this->get('/tasks/' . $a['id'])->body;
        self::assertStringContainsString('<label for="f-required_id">Ajouter une action requise</label>', $body);
        self::assertStringContainsString('<option value="" selected>Choisir une tâche…</option>', $body);
        self::assertStringContainsString('<option value="' . $a['id'] . '">Alpha — À faire (cette tâche)</option>', $body);
        self::assertStringContainsString('<option value="' . $b['id'] . '">Bêta — En cours</option>', $body);
    }

    public function testDirectCycleIsRefusedAndMatchesRest(): void
    {
        $a = $this->seed('A');
        $b = $this->seed('B');
        $this->addLink($a['id'], $b['id']);
        $before = $this->snapshot();
        $r = $this->addLink($b['id'], $a['id']);
        $this->assertHtml($r, 409);
        self::assertStringContainsString('Ajouter cette action requise créerait un cycle.', $r->body);
        self::assertSame($before, $this->snapshot(), 'aucun lien créé, tâches inchangées');
        $rest = $this->kernel->handle(new Request('PUT', '/api/v1/tasks/' . $b['id'] . '/required-actions/' . $a['id']));
        self::assertSame(409, $rest->status);
        self::assertSame('dependency_cycle', json_decode($rest->body, true)['error']['code']);
    }

    public function testIndirectCycleIsRefused(): void
    {
        $a = $this->seed('A');
        $b = $this->seed('B');
        $c = $this->seed('C');
        $this->addLink($a['id'], $b['id']);
        $this->addLink($b['id'], $c['id']);
        $before = $this->snapshot();
        $r = $this->addLink($c['id'], $a['id']);
        $this->assertHtml($r, 409);
        self::assertStringContainsString('créerait un cycle', $r->body);
        self::assertSame($before, $this->snapshot());
        self::assertSame(2, $this->links());
    }

    public function testSelfLinkIsRefusedAndMatchesRest(): void
    {
        $a = $this->seed('A');
        $before = $this->snapshot();
        $r = $this->addLink($a['id'], $a['id']);
        $this->assertHtml($r, 422);
        self::assertStringContainsString('Une tâche ne peut pas se requérir elle-même.', $r->body);
        self::assertStringContainsString('role="alert"', $r->body);
        self::assertSame($before, $this->snapshot());
        $rest = $this->kernel->handle(new Request('PUT', '/api/v1/tasks/' . $a['id'] . '/required-actions/' . $a['id']));
        self::assertSame('dependency_self', json_decode($rest->body, true)['error']['code']);
    }

    public function testUnknownRequiredTaskIs422(): void
    {
        $a = $this->seed('A');
        $r = $this->addLink($a['id'], 999);
        $this->assertHtml($r, 422);
        self::assertStringContainsString('Cette action requise n&#039;existe plus.', $r->body);
        self::assertSame(0, $this->links());
    }

    public function testMissingOrInvalidChoiceIs422WithoutCallingTheUseCase(): void
    {
        $a = $this->seed('A');
        $this->seed('B');
        $before = $this->snapshot();
        foreach ([[], ['required_id' => ''], ['required_id' => 'abc'], ['required_id' => '0'], ['required_id' => '-1'], ['required_id' => ['1']], ['required_id' => '99999999999999999999']] as $form) {
            $r = $this->post('/tasks/' . $a['id'] . '/required-actions', $form);
            $this->assertHtml($r, 422);
            self::assertStringContainsString('Choisissez une tâche.', $r->body);
            self::assertStringContainsString('aria-invalid="true"', $r->body);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testRefusedChoiceStaysSelected(): void
    {
        $a = $this->seed('A');
        $b = $this->seed('B');
        $this->addLink($a['id'], $b['id']);
        $r = $this->addLink($b['id'], $a['id']);
        self::assertStringContainsString('<option value="' . $a['id'] . '" selected>', $r->body);
    }

    public function testDuplicateIsSuccessWithInformation(): void
    {
        $req = $this->seed('Prérequis');
        $main = $this->seed('Principale');
        $this->addLink($main['id'], $req['id']);
        $this->pdo->exec("UPDATE tasks SET updated_at = '2020-01-01T00:00:00Z'");
        $before = $this->snapshot();
        $body = $this->followed($this->addLink($main['id'], $req['id']), $main['id']);
        self::assertStringContainsString('Cette action requise était déjà présente.', $body);
        self::assertSame(1, $this->links());
        self::assertSame($before, $this->snapshot(), 'updated_at inchangé');
    }

    public function testRemoveIsIdempotentWithSameMessage(): void
    {
        $req = $this->seed('Prérequis');
        $main = $this->seed('Principale');
        $this->addLink($main['id'], $req['id']);
        $path = '/tasks/' . $main['id'] . '/required-actions/' . $req['id'] . '/delete';
        self::assertStringContainsString('Action requise retirée.', $this->followed($this->post($path), $main['id']));
        self::assertSame(0, $this->links());
        self::assertSame(2, $this->rows());
        self::assertStringContainsString('Action requise retirée.', $this->followed($this->post($path), $main['id']));
    }

    public function testUnknownTaskAndInvalidIdsAre404(): void
    {
        $a = $this->seed('A');
        $this->assertHtml($this->post('/tasks/999/required-actions', ['required_id' => (string) $a['id']]), 404);
        $this->assertHtml($this->post('/tasks/999/required-actions/1/delete'), 404);
        $this->assertHtml($this->post('/tasks/999/status', ['status' => 'in_progress']), 404);
        $this->assertHtml($this->get('/tasks/999/reopen'), 404);
        foreach (['/tasks/' . $a['id'] . '/required-actions/abc/delete', '/tasks/abc/required-actions', '/tasks/' . $a['id'] . '/required-actions/0/delete'] as $path) {
            $this->assertHtml($this->post($path), 404);
        }
    }

    public function testNewRoutesRefuseOtherMethods(): void
    {
        $a = $this->seed('A');
        $before = $this->snapshot();
        foreach (['/tasks/1/required-actions', '/tasks/1/required-actions/1/delete', '/tasks/1/status'] as $path) {
            $r = $this->get($path);
            self::assertSame(405, $r->status, $path);
            self::assertSame('POST', $r->headers['Allow']);
        }
        self::assertSame('GET', $this->post('/tasks/' . $a['id'] . '/reopen')->headers['Allow']);
        self::assertSame($before, $this->snapshot());
    }

    public function testAddingUnfinishedActionToDoneTaskIsAccepted(): void
    {
        $done = $this->seed('Finie');
        $this->service->changeStatus($done['id'], 'in_progress');
        $this->service->changeStatus($done['id'], 'done');
        $open = $this->seed('Ouverte');
        $body = $this->followed($this->addLink($done['id'], $open['id']), $done['id']);
        self::assertStringContainsString('Action requise ajoutée.', $body);
        self::assertStringNotContainsString('avertissement', mb_strtolower($body));
    }

    // ----- Changement d'état -----

    /** @return list<string> libellés des boutons d'état du détail */
    private function stateButtons(int $id): array
    {
        $body = $this->get('/tasks/' . $id)->body;
        preg_match('#<section aria-labelledby="h-status">(.*?)</section>#s', $body, $m);
        preg_match_all('#(?:<button type="submit">|<a class="button"[^>]*>)([^<]+)<#', $m[1], $buttons);

        return $buttons[1];
    }

    public function testButtonsMatchAllowedTargetsForEachState(): void
    {
        $t = $this->seed('T');
        $expected = ['todo' => ['Démarrer'], 'in_progress' => ['Terminer', 'Remettre à faire'], 'done' => ['Rouvrir']];
        foreach (['todo', 'in_progress', 'done'] as $state) {
            if ($state === 'in_progress') {
                $this->service->changeStatus($t['id'], 'in_progress');
            } elseif ($state === 'done') {
                $this->service->changeStatus($t['id'], 'done');
            }
            self::assertSame($expected[$state], $this->stateButtons($t['id']), $state);
            self::assertCount(count(TaskStatus::from($state)->allowedTargets()), $expected[$state], 'cohérent avec la table du domaine');
        }
    }

    public function testEveryAllowedTransitionWorks(): void
    {
        $t = $this->seed('T');
        $id = $t['id'];
        $steps = [
            ['in_progress', 'Tâche démarrée.', 'En cours'],
            ['todo', 'Tâche remise à faire.', 'À faire'],
            ['in_progress', 'Tâche démarrée.', 'En cours'],
            ['done', 'Tâche terminée.', 'Terminée'],
            ['todo', 'Tâche rouverte.', 'À faire'],
        ];
        foreach ($steps as [$target, $flash, $label]) {
            $body = $this->followed($this->changeTo($id, $target), $id);
            self::assertStringContainsString($flash, $body);
            self::assertStringContainsString('status-' . $target . '">' . $label, $body);
            self::assertSame($target, $this->service->get($id)['status']);
        }
        $this->changeTo($id, 'in_progress');
        $this->changeTo($id, 'done');
        self::assertStringContainsString('terminée le', $this->get('/tasks/' . $id)->body);
        $this->changeTo($id, 'todo');
        self::assertStringNotContainsString('terminée le', $this->get('/tasks/' . $id)->body);
    }

    public function testRefusedTransitionsLeaveTaskUntouched(): void
    {
        $todo = $this->seed('Todo');
        $done = $this->seed('Done');
        $this->service->changeStatus($done['id'], 'in_progress');
        $this->service->changeStatus($done['id'], 'done');
        $this->pdo->exec("UPDATE tasks SET updated_at = '2020-01-01T00:00:00Z'");
        $before = $this->snapshot();
        foreach ([[$todo['id'], 'done', 'À faire', 'Terminée'], [$done['id'], 'in_progress', 'Terminée', 'En cours'], [$todo['id'], 'todo', 'À faire', 'À faire']] as [$id, $target, $from, $to]) {
            $r = $this->changeTo($id, $target);
            $this->assertHtml($r, 409);
            self::assertStringContainsString('Ce changement d&#039;état n&#039;est plus possible : la tâche est « ' . $from . ' », elle ne peut pas passer à « ' . $to . ' ».', $r->body);
            self::assertStringContainsString('role="alert"', $r->body);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testForgedStatusValueIs422(): void
    {
        $t = $this->seed('T');
        $before = $this->snapshot();
        foreach ([['status' => 'foo'], ['status' => ''], [], ['status' => ['done']], ['status' => 'DONE']] as $form) {
            $this->assertHtml($this->post('/tasks/' . $t['id'] . '/status', $form), 422);
        }
        self::assertStringNotContainsString('todo, in_progress', $this->post('/tasks/' . $t['id'] . '/status', ['status' => 'foo'])->body);
        self::assertSame($before, $this->snapshot());
    }

    public function testCompletionRefusedWithUnfinishedRequiredActionsThenAcceptedInOrder(): void
    {
        $a = $this->seed('Prérequis A');
        $b = $this->seed('Prérequis B');
        $main = $this->seed('Principale');
        $this->addLink($main['id'], $a['id']);
        $this->addLink($main['id'], $b['id']);
        $this->service->changeStatus($main['id'], 'in_progress');
        $this->service->changeStatus($a['id'], 'in_progress');
        $this->service->changeStatus($a['id'], 'done');
        $this->pdo->exec("UPDATE tasks SET updated_at = '2020-01-01T00:00:00Z'");
        $before = $this->snapshot();

        $r = $this->changeTo($main['id'], 'done');
        $this->assertHtml($r, 409);
        self::assertStringContainsString('Terminaison impossible : ces actions requises ne sont pas terminées :', $r->body);
        preg_match('#<section aria-labelledby="h-status">(.*?)</section>#s', $r->body, $m);
        self::assertStringContainsString('<a href="/tasks/' . $b['id'] . '">Prérequis B</a> — À faire', $m[1]);
        self::assertStringNotContainsString('Prérequis A', $m[1], 'seules les actions non terminées sont listées');
        self::assertSame($before, $this->snapshot());

        $this->service->changeStatus($b['id'], 'in_progress');
        $this->service->changeStatus($b['id'], 'done');
        self::assertStringContainsString('Tâche terminée.', $this->followed($this->changeTo($main['id'], 'done'), $main['id']));
    }

    public function testCompletionOfRecurringTaskShowsNextOccurrence(): void
    {
        $t = $this->seed('Loyer', ['due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $this->service->changeStatus($t['id'], 'in_progress');
        $this->assertRedirect($this->changeTo($t['id'], 'done'), '/tasks/' . $t['id']);
        $body = $this->get('/tasks/' . $t['id'])->body;
        self::assertStringContainsString('Tâche terminée. Prochaine occurrence : « Loyer », échéance 28/02/2026.', html_entity_decode($body, ENT_QUOTES, 'UTF-8'));
        self::assertStringContainsString('<a href="/tasks/2">Voir la prochaine occurrence</a>', $body);
        $next = $this->service->get(2);
        self::assertSame('todo', $next['status']);
        self::assertSame('2026-02-28', $next['due_date']);
        self::assertSame([], $next['required_actions']);
        self::assertSame(2, $this->rows());
    }

    public function testCompletionOfNonRecurringTaskCreatesNothing(): void
    {
        $t = $this->seed('Simple');
        $this->service->changeStatus($t['id'], 'in_progress');
        $body = $this->followed($this->changeTo($t['id'], 'done'), $t['id']);
        self::assertStringContainsString('Tâche terminée.', $body);
        self::assertStringNotContainsString('Prochaine occurrence', $body);
        self::assertSame(1, $this->rows());
    }

    public function testDueDateOverflowIsRefused(): void
    {
        $t = $this->seed('Limite', ['due_date' => '9999-12-31', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->service->changeStatus($t['id'], 'in_progress');
        $before = $this->snapshot();
        $r = $this->changeTo($t['id'], 'done');
        $this->assertHtml($r, 409);
        self::assertStringContainsString('La prochaine échéance dépasserait le 31/12/9999', $r->body);
        self::assertSame($before, $this->snapshot());
    }

    public function testSecondCompletionIsRefusedWithSingleOccurrence(): void
    {
        $t = $this->seed('Deux fois', ['due_date' => '2026-01-10', 'recurrence' => ['interval' => 1, 'unit' => 'week']]);
        $this->service->changeStatus($t['id'], 'in_progress');
        $this->changeTo($t['id'], 'done');
        $r = $this->changeTo($t['id'], 'done');
        $this->assertHtml($r, 409);
        self::assertStringContainsString('« Terminée »', $r->body);
        self::assertSame(2, $this->rows());
    }

    public function testRestParityOnRefusals(): void
    {
        $req = $this->seed('Prérequis');
        $main = $this->seed('Principale');
        $this->addLink($main['id'], $req['id']);
        $this->service->changeStatus($main['id'], 'in_progress');
        $portal = $this->changeTo($main['id'], 'done');
        $rest = $this->kernel->handle(new Request('PATCH', '/api/v1/tasks/' . $main['id'], [], '{"status":"done"}'));
        self::assertSame($rest->status, $portal->status);
        self::assertSame('required_actions_not_done', json_decode($rest->body, true)['error']['code']);
        $portal = $this->changeTo($req['id'], 'done');
        $rest = $this->kernel->handle(new Request('PATCH', '/api/v1/tasks/' . $req['id'], [], '{"status":"done"}'));
        self::assertSame($rest->status, $portal->status);
        self::assertSame('status_transition_invalid', json_decode($rest->body, true)['error']['code']);
    }

    // ----- Réouverture -----

    public function testReopenPageWarnsForFinishedRecurringTask(): void
    {
        $t = $this->seed('Périodique', ['due_date' => '2026-01-10', 'recurrence' => ['interval' => 1, 'unit' => 'week']]);
        $this->service->changeStatus($t['id'], 'in_progress');
        $this->service->changeStatus($t['id'], 'done');
        self::assertSame(['Rouvrir'], $this->stateButtons($t['id']));
        self::assertStringContainsString('href="/tasks/' . $t['id'] . '/reopen"', $this->get('/tasks/' . $t['id'])->body);
        $r = $this->get('/tasks/' . $t['id'] . '/reopen');
        $this->assertHtml($r, 200);
        self::assertStringContainsString('Cette tâche est périodique. Une occurrence suivante a pu être créée', $r->body);
        self::assertStringContainsString('Rouvrir quand même', $r->body);
        self::assertStringContainsString('<a href="/tasks/' . $t['id'] . '">Annuler</a>', $r->body);
        self::assertSame('done', $this->service->get($t['id'])['status'], 'le GET ne rouvre rien');
        $this->assertRedirect($this->post('/tasks/' . $t['id'] . '/status', ['status' => 'todo']), '/tasks/' . $t['id']);
        $after = $this->service->get($t['id']);
        self::assertSame('todo', $after['status']);
        self::assertNull($after['completed_at']);
    }

    public function testReopenDirectWithoutWarningForNonRecurringAndRedirectsWhenNotApplicable(): void
    {
        $t = $this->seed('Simple');
        $this->assertRedirect($this->get('/tasks/' . $t['id'] . '/reopen'), '/tasks/' . $t['id']);
        $this->service->changeStatus($t['id'], 'in_progress');
        $this->service->changeStatus($t['id'], 'done');
        $this->assertRedirect($this->get('/tasks/' . $t['id'] . '/reopen'), '/tasks/' . $t['id']);
        $body = $this->get('/tasks/' . $t['id'])->body;
        self::assertStringContainsString('action="/tasks/' . $t['id'] . '/status"', $body);
        self::assertStringNotContainsString('/reopen', $body);
        $p = $this->seed('Périodique todo', ['due_date' => '2026-01-10', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->assertRedirect($this->get('/tasks/' . $p['id'] . '/reopen'), '/tasks/' . $p['id']);
    }

    // ----- Garde -----

    public function testGuardAppliesToEveryNewPostRoute(): void
    {
        $a = $this->seed('A');
        $b = $this->seed('B');
        $this->addLink($a['id'], $b['id']);
        $before = $this->snapshot();
        $routes = [
            ['/tasks/' . $a['id'] . '/required-actions', ['required_id' => (string) $b['id']]],
            ['/tasks/' . $a['id'] . '/required-actions/' . $b['id'] . '/delete', []],
            ['/tasks/' . $a['id'] . '/status', ['status' => 'in_progress']],
        ];
        foreach ($routes as [$path, $form]) {
            self::assertSame(403, $this->post($path, $form, withToken: false)->status, $path);
            self::assertSame(403, $this->post($path, $form + ['_csrf' => 'faux'], withToken: false)->status, $path);
            self::assertSame(403, $this->post($path, $form, ['origin' => 'http://evil.example'])->status, $path);
            self::assertSame(403, $this->post($path, $form, ['origin' => 'null'])->status, $path);
            self::assertSame(403, $this->kernel->handle(new Request('POST', $path, ['host' => 'evil.example'], '', [], $form + ['_csrf' => $this->session->csrfToken()]))->status, $path);
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame('same-origin', $this->get('/tasks/' . $a['id'])->headers['Referrer-Policy']);
    }

    public function testFormsOfDetailCarryTokenAndNoGetWrites(): void
    {
        $a = $this->seed('A');
        $b = $this->seed('B');
        $this->addLink($a['id'], $b['id']);
        $body = $this->get('/tasks/' . $a['id'])->body;
        self::assertSame(substr_count($body, '<form method="post"'), substr_count($body, 'name="_csrf" value="' . $this->session->csrfToken() . '"'));
        self::assertGreaterThanOrEqual(3, substr_count($body, '<form method="post"'));
        self::assertStringContainsString('Retirer<span class="visually-hidden"> B</span>', $body);
    }

    // ----- Échappement et erreurs internes -----

    public function testScriptTagsAreEscapedInNewPagesAndMessages(): void
    {
        $req = $this->seed(self::XSS);
        $main = $this->seed(self::XSS . ' périodique', ['due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $this->addLink($main['id'], $req['id']);
        $this->service->changeStatus($main['id'], 'in_progress');
        $pages = [
            'détail et liste déroulante' => $this->get('/tasks/' . $main['id'])->body,
            'refus de terminaison' => $this->changeTo($main['id'], 'done')->body,
            'refus de cycle' => $this->addLink($req['id'], $main['id'])->body,
        ];
        $this->service->changeStatus($req['id'], 'in_progress');
        $this->service->changeStatus($req['id'], 'done');
        $this->changeTo($main['id'], 'done');
        $pages['flash de terminaison'] = $this->get('/tasks/' . $main['id'])->body;
        $this->changeTo($main['id'], 'done');
        $pages['page de réouverture'] = $this->get('/tasks/' . $main['id'] . '/reopen')->body;
        foreach ($pages as $name => $body) {
            self::assertStringNotContainsString('<script', $body, $name);
            self::assertStringContainsString(self::XSS_ESCAPED, $body, $name);
        }
    }

    public function testUnavailableDatabaseOnNewRoutesLeaksNothing(): void
    {
        $portal = new PortalAdapter(new TaskService(new TaskRepository(static function (): \PDO {
            throw new \PDOException('SQLSTATE[XX000] secret_table password=hunter2');
        })), $this->session, ['127.0.0.1']);
        foreach ([
            ['POST', '/tasks/1/status', ['status' => 'done']],
            ['POST', '/tasks/1/required-actions', ['required_id' => '2']],
            ['POST', '/tasks/1/required-actions/2/delete', []],
            ['GET', '/tasks/1/reopen', []],
        ] as [$method, $path, $form]) {
            $r = $portal->handle(new Request($method, $path, ['host' => '127.0.0.1'], '', [], $form + ($method === 'POST' ? ['_csrf' => $this->session->csrfToken()] : [])));
            self::assertSame(503, $r->status, $path);
            foreach (['SQLSTATE', 'secret_table', 'hunter2', 'PDO'] as $leak) {
                self::assertStringNotContainsString($leak, $r->body);
            }
        }
    }
}
