<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\InvalidTask;
use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Migrator;
use Actions\Portal\Messages;
use Actions\Portal\PortalAdapter;
use Actions\Portal\TaskForm;
use Actions\Tests\Support\ArraySession;
use PHPUnit\Framework\Attributes\DataProvider;

/** Portail B1 (consulter, créer, modifier, supprimer) : Kernel -> PortalAdapter -> TaskService -> PostgreSQL réel. */
final class PortalTasksTest extends DbTestCase
{
    private const HOST = '127.0.0.1:8080';
    private const XSS = '<script>alert(1)</script>';

    private Kernel $kernel;
    private ArraySession $session;
    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->session = new ArraySession();
        $this->kernel = Bootstrap::kernel($this->config, $this->session);
        $this->service = Bootstrap::taskService($this->config);
    }

    /** @param array<string,mixed> $query @param array<string,string> $headers */
    private function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, ['host' => self::HOST] + $headers, '', $query));
    }

    /** @param array<string,mixed> $form @param array<string,string> $headers */
    private function post(string $path, array $form = [], array $headers = [], bool $withToken = true): Response
    {
        if ($withToken) {
            $form += ['_csrf' => $this->session->csrfToken()];
        }
        $form += ['importance' => 'Mineure']; // le navigateur envoie toujours la liste; l'absence du champ est testée dans TaskImportanceTest

        return $this->kernel->handle(new Request('POST', $path, ['host' => self::HOST] + $headers, '', [], $form));
    }

    /** @return array<string,mixed> */
    private function seed(array $data = []): array
    {
        return $this->service->create($data + ['title' => 'Tâche', 'project' => 'Divers Pro']);
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn();
    }

    private function links(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM task_dependencies')->fetchColumn();
    }

    private function snapshot(): string
    {
        return (string) json_encode($this->pdo->query('SELECT * FROM tasks ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function assertHtml(Response $r, int $status): void
    {
        self::assertSame($status, $r->status, $r->body);
        self::assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
    }

    private function assertRedirect(Response $r, string $location): void
    {
        self::assertSame(303, $r->status, $r->body);
        self::assertSame($location, $r->headers['Location']);
        self::assertSame('', $r->body, 'Post/Redirect/Get : aucune page rendue en réponse au POST');
    }

    private static function text(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    // ----- Parcours et consultation -----

    public function testFullJourney(): void
    {
        $list = $this->get('/tasks');
        $this->assertHtml($list, 200);
        self::assertStringContainsString('<html lang="fr">', $list->body);
        self::assertStringContainsString('Aucune tâche.', $list->body);

        $form = $this->get('/tasks/new');
        $this->assertHtml($form, 200);
        // Le jeton est lu dans la page de formulaire précédente.
        self::assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $m));
        $created = $this->post('/tasks', ['_csrf' => $m[1], 'title' => 'Préparer le budget', 'project' => 'Divers Pro'], withToken: false);
        $this->assertRedirect($created, '/tasks/1');

        $detail = $this->get('/tasks/1');
        $this->assertHtml($detail, 200);
        self::assertStringContainsString('Tâche créée.', $detail->body);
        self::assertStringContainsString('role="status"', $detail->body);
        self::assertStringContainsString('Préparer le budget', $detail->body);
        self::assertStringNotContainsString('Tâche créée.', $this->get('/tasks/1')->body, 'le message flash est lu une seule fois');

        $edit = $this->get('/tasks/1/edit');
        $this->assertHtml($edit, 200);
        self::assertStringContainsString('value="Préparer le budget"', $edit->body);
        $this->assertRedirect($this->post('/tasks/1', ['title' => 'Budget 2027', 'project' => 'Divers Perso']), '/tasks/1');
        self::assertStringContainsString('Tâche enregistrée.', $this->get('/tasks/1')->body);

        $list = $this->get('/tasks');
        self::assertStringContainsString('Budget 2027', $list->body);
        self::assertStringContainsString('Perso', $list->body);
        self::assertStringContainsString('À faire', $list->body);

        $confirm = $this->get('/tasks/1/delete');
        $this->assertHtml($confirm, 200);
        self::assertStringContainsString('Budget 2027', $confirm->body);
        self::assertSame(1, $this->rows(), 'le GET ne supprime rien');
        $this->assertRedirect($this->post('/tasks/1/delete'), '/tasks');
        self::assertSame(0, $this->rows());
        self::assertStringContainsString('Tâche « Budget 2027 » supprimée.', $this->get('/tasks')->body);
        self::assertSame(404, $this->get('/tasks/1')->status);
    }

    public function testMinimalAndFullCreationAreKept(): void
    {
        $this->assertRedirect($this->post('/tasks', ['title' => 'Minimale', 'project' => 'Divers Perso', 'due_date' => '', 'recurrence_interval' => '', 'recurrence_unit' => '', 'estimated_minutes' => '', 'description' => '']), '/tasks/1');
        $minimal = $this->service->get(1);
        self::assertNull($minimal['due_date']);
        self::assertNull($minimal['recurrence']);
        self::assertNull($minimal['estimated_minutes']);
        self::assertNull($minimal['description']);

        $this->assertRedirect($this->post('/tasks', [
            'title' => 'Complète', 'project' => 'Divers Pro', 'due_date' => '2026-02-28',
            'recurrence_interval' => '2', 'recurrence_unit' => 'month', 'estimated_minutes' => '90',
            'description' => "Ligne 1\nLigne 2",
        ]), '/tasks/2');
        $full = $this->service->get(2);
        self::assertSame('2026-02-28', $full['due_date']);
        self::assertSame(['interval' => 2, 'unit' => 'month'], $full['recurrence']);
        self::assertSame(90, $full['estimated_minutes']);
        self::assertSame("Ligne 1\nLigne 2", $full['description']);

        $detail = self::text($this->get('/tasks/2')->body);
        foreach (['Pro', '28/02/2026', 'Tous les 2 mois', '90 minutes', "Ligne 1\nLigne 2"] as $expected) {
            self::assertStringContainsString($expected, $detail);
        }
        $list = self::text($this->get('/tasks')->body);
        self::assertStringContainsString('28/02/2026', $list);
        self::assertStringContainsString('Tous les 2 mois', $list);
    }

    public function testDetailShowsParisTimestampsAndCompletion(): void
    {
        $task = $this->seed(['title' => 'Horodatée']);
        $this->pdo->exec("UPDATE tasks SET created_at = '2026-07-15T12:30:00Z', updated_at = '2026-01-15T12:30:00Z', status = 'done', completed_at = '2026-12-31T23:30:00Z' WHERE id = " . $task['id']);
        $text = self::text($this->get('/tasks/' . $task['id'])->body);
        self::assertStringContainsString('15/07/2026 14:30', $text, 'été : UTC+2');
        self::assertStringContainsString('15/01/2026 13:30', $text, 'hiver : UTC+1');
        self::assertStringContainsString('terminée le 01/01/2027 00:30', $text);
        self::assertStringContainsString('Terminée', $text);
    }

    public function testDetailListsRequiredActionsWithTheirState(): void
    {
        $a = $this->seed(['title' => 'Prérequis A']);
        $b = $this->seed(['title' => 'Prérequis B']);
        $main = $this->seed(['title' => 'Principale']);
        $this->service->addRequiredAction($main['id'], $a['id']);
        $this->service->addRequiredAction($main['id'], $b['id']);
        $this->service->changeStatus($a['id'], 'in_progress');
        $before = $this->snapshot();

        $body = $this->get('/tasks/' . $main['id'])->body;
        self::assertStringContainsString('<a href="/tasks/' . $a['id'] . '">Prérequis A</a>', $body);
        self::assertStringContainsString('<a href="/tasks/' . $b['id'] . '">Prérequis B</a>', $body);
        self::assertMatchesRegularExpression('#Prérequis A</a>\s*—\s*<span class="status status-in_progress">En cours</span>#', $body);
        self::assertMatchesRegularExpression('#Prérequis B</a>\s*—\s*<span class="status status-todo">À faire</span>#', $body);
        self::assertStringContainsString('Aucune action requise.', $this->get('/tasks/' . $a['id'])->body);
        self::assertSame($before, $this->snapshot(), 'consulter ne modifie rien');
    }

    public function testDistinctPageTitlesAndNoScriptNorExternalResource(): void
    {
        $this->seed(['title' => 'Une tâche']);
        $titles = [];
        foreach (['/tasks', '/tasks/new', '/tasks/1', '/tasks/1/edit', '/tasks/1/delete', '/tasks/999'] as $path) {
            $body = $this->get($path)->body;
            self::assertSame(1, preg_match('#<title>(.*?)</title>#', $body, $m), $path);
            $titles[] = $m[1];
            self::assertStringNotContainsString('<script', $body, $path);
            self::assertStringNotContainsString('http://', $body, $path);
            self::assertStringNotContainsString('https://', $body, $path);
            self::assertStringContainsString('<link rel="stylesheet" href="/assets/portal.css">', $body);
        }
        self::assertSame($titles, array_values(array_unique($titles)), 'titres de page distincts');
        self::assertFileExists(__DIR__ . '/../../public/assets/portal.css');
    }

    // ----- Filtres -----

    private function populate(): void
    {
        $this->seed(['title' => 'Pro todo', 'project' => 'Divers Pro', 'due_date' => '2026-01-01']);
        $b = $this->seed(['title' => 'Pro encours', 'project' => 'Divers Pro', 'due_date' => '2026-01-02']);
        $c = $this->seed(['title' => 'Pro finie', 'project' => 'Divers Pro', 'due_date' => '2026-01-03']);
        $this->seed(['title' => 'Perso todo', 'project' => 'Divers Perso', 'due_date' => '2026-01-04']);
        $d = $this->seed(['title' => 'Perso finie', 'project' => 'Divers Perso']);
        $this->service->changeStatus($b['id'], 'in_progress');
        $this->service->changeStatus($c['id'], 'in_progress');
        $this->service->changeStatus($c['id'], 'done');
        $this->service->changeStatus($d['id'], 'in_progress');
        $this->service->changeStatus($d['id'], 'done');
    }

    /** @return list<string> titres affichés dans l'ordre */
    private function titles(Response $r): array
    {
        preg_match_all('#<td><a href="/tasks/\d+">(.*?)</a></td>#', $r->body, $m);

        return array_map(static fn (string $t): string => html_entity_decode($t, ENT_QUOTES, 'UTF-8'), $m[1]);
    }

    public function testFilters(): void
    {
        $this->populate();
        self::assertSame(['Pro todo', 'Pro encours', 'Pro finie', 'Perso todo', 'Perso finie'], $this->titles($this->get('/tasks')), 'sans filtre : tout, tri REST');
        self::assertSame(['Pro todo', 'Perso todo'], $this->titles($this->get('/tasks', ['status' => 'todo'])));
        self::assertSame(['Pro encours'], $this->titles($this->get('/tasks', ['status' => 'in_progress'])));
        self::assertSame(['Pro finie', 'Perso finie'], $this->titles($this->get('/tasks', ['status' => 'done'])));
        self::assertSame(['Perso todo', 'Perso finie'], $this->titles($this->get('/tasks', ['category' => 'Perso'])));
        self::assertSame(['Pro finie'], $this->titles($this->get('/tasks', ['status' => 'done', 'category' => 'Pro'])), 'ET');
        self::assertSame(['Pro todo', 'Pro encours', 'Perso todo'], $this->titles($this->get('/tasks', ['hide_done' => '1'])));
        self::assertSame(['Perso todo'], $this->titles($this->get('/tasks', ['hide_done' => '1', 'category' => 'Perso'])));
        self::assertSame(['Pro todo', 'Pro encours', 'Pro finie', 'Perso todo', 'Perso finie'], $this->titles($this->get('/tasks', ['status' => '', 'category' => ''])), 'valeur vide = tous');
        self::assertSame(5, count($this->titles($this->get('/tasks', ['hide_done' => '0', 'inconnu' => 'x']))), 'hide_done=0 et paramètre inconnu : ignorés');
    }

    public function testFilterStateStaysVisibleAndEmptyMessagesDiffer(): void
    {
        $this->populate();
        $body = $this->get('/tasks', ['status' => 'in_progress', 'project' => 'Divers Perso', 'hide_done' => '1'])->body;
        self::assertStringContainsString('<option value="in_progress" selected>En cours</option>', $body);
        self::assertStringContainsString('<option value="Divers Perso" selected>Divers Perso</option>', $body);
        self::assertMatchesRegularExpression('/<input type="checkbox" id="f-hide-done" name="hide_done" value="1" checked>/', $body);
        self::assertStringContainsString('Aucune tâche ne correspond aux filtres.', $body);
        self::assertStringContainsString('Filtres actifs', $body);

        $both = $this->get('/tasks', ['status' => 'done', 'hide_done' => '1']);
        self::assertSame([], $this->titles($both));
        self::assertStringContainsString('Aucune tâche ne correspond aux filtres.', $both->body);
        self::assertStringContainsString('tâches terminées masquées', $both->body);
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFilterIs422WithoutList(array $query): void
    {
        $this->populate();
        $r = $this->get('/tasks', $query);
        $this->assertHtml($r, 422);
        self::assertSame([], $this->titles($r));
        self::assertStringContainsString('Filtre invalide', $r->body);
        self::assertStringNotContainsString('Pro todo', $r->body);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidFilters(): array
    {
        return [
            'état inconnu' => [['status' => 'bogus']],
            'état en français' => [['status' => 'Terminée']],
            'catégorie casse' => [['category' => 'pro']],
            'tableau' => [['status' => ['todo']]],
        ];
    }

    // ----- Validation et parité avec REST -----

    /** @return array<string,array{array<string,string>,string,string}> saisie, code attendu, champ (suffixe d'id) */
    public static function invalidForms(): array
    {
        $ok = ['title' => 'T', 'project' => 'Divers Pro', 'importance' => 'Mineure', 'due_date' => '', 'recurrence_interval' => '', 'recurrence_unit' => '', 'estimated_minutes' => '', 'description' => ''];

        return [
            'titre vide' => [['title' => ''] + $ok, 'title_blank', 'title'],
            'titre blanc' => [['title' => " \t\n "] + $ok, 'title_blank', 'title'],
            'titre trop long' => [['title' => str_repeat('é', 256)] + $ok, 'title_too_long', 'title'],
            'projet vide (aucun choix)' => [['project' => ''] + $ok, 'project_invalid', 'project'],
            'projet inconnu' => [['project' => 'Inconnu'] + $ok, 'project_invalid', 'project'],
            'projet casse' => [['project' => 'divers pro'] + $ok, 'project_invalid', 'project'],
            'date inexistante' => [['due_date' => '2026-02-30'] + $ok, 'due_date_invalid', 'due_date'],
            'date non ISO' => [['due_date' => '28/02/2026'] + $ok, 'due_date_invalid', 'due_date'],
            'périodicité sans unité' => [['due_date' => '2026-01-01', 'recurrence_interval' => '2'] + $ok, 'recurrence_incomplete', 'recurrence'],
            'périodicité sans nombre' => [['due_date' => '2026-01-01', 'recurrence_unit' => 'day'] + $ok, 'recurrence_incomplete', 'recurrence'],
            'intervalle nul' => [['due_date' => '2026-01-01', 'recurrence_interval' => '0', 'recurrence_unit' => 'day'] + $ok, 'recurrence_interval_invalid', 'recurrence'],
            'intervalle non entier' => [['due_date' => '2026-01-01', 'recurrence_interval' => 'abc', 'recurrence_unit' => 'day'] + $ok, 'field_type_invalid', 'recurrence'],
            'unité inconnue' => [['due_date' => '2026-01-01', 'recurrence_interval' => '2', 'recurrence_unit' => 'fortnight'] + $ok, 'recurrence_unit_invalid', 'recurrence'],
            'périodicité sans date' => [['recurrence_interval' => '2', 'recurrence_unit' => 'day'] + $ok, 'recurrence_requires_due_date', 'recurrence'],
            'durée nulle' => [['estimated_minutes' => '0'] + $ok, 'estimated_minutes_invalid', 'estimated_minutes'],
            'durée non entière' => [['estimated_minutes' => '1.5'] + $ok, 'field_type_invalid', 'estimated_minutes'],
            'durée énorme' => [['estimated_minutes' => '99999999999999999999'] + $ok, 'field_type_invalid', 'estimated_minutes'],
            'description avec octet nul' => [['description' => "a\0b"] + $ok, 'text_invalid', 'description'],
        ];
    }

    /** @param array<string,string> $form */
    #[DataProvider('invalidForms')]
    public function testCreateRefusalKeepsInputAndMatchesRest(array $form, string $code, string $field): void
    {
        $r = $this->post('/tasks', $form);
        $this->assertHtml($r, 422);
        self::assertSame(0, $this->rows());

        // Même règle, même code que REST pour la même entrée (le portail ne revalide rien).
        $input = TaskForm::toInput($form);
        try {
            $this->service->create($input);
            self::fail('Le cas d\'usage aurait dû refuser.');
        } catch (InvalidTask $e) {
            self::assertSame($code, $e->code());
            $expectedMessage = Messages::for($e);
        }
        $rest = $this->kernel->handle(new Request('POST', '/api/v1/tasks', [], json_encode($input, JSON_THROW_ON_ERROR)));
        self::assertSame(422, $rest->status);
        self::assertSame($code, json_decode($rest->body, true)['error']['code']);

        self::assertStringContainsString('role="alert"', $r->body);
        self::assertStringContainsString(htmlspecialchars($expectedMessage, ENT_QUOTES), $r->body);
        self::assertStringContainsString('id="e-' . $field . '"', $r->body, 'message près du champ');
        self::assertStringContainsString('aria-invalid="true"', $r->body);
        self::assertStringContainsString('href="#f-', $r->body, 'résumé relié au champ');
        // Saisie conservée.
        if ($form['title'] !== '' && !str_contains($form['title'], "\t")) {
            self::assertStringContainsString('value="' . htmlspecialchars($form['title'], ENT_QUOTES) . '"', $r->body);
        }
        foreach (['due_date', 'recurrence_interval', 'estimated_minutes'] as $name) {
            if ($form[$name] !== '') {
                self::assertStringContainsString('name="' . $name . '"', $r->body);
                self::assertStringContainsString('value="' . htmlspecialchars($form[$name], ENT_QUOTES) . '"', $r->body);
            }
        }
        if ($form['project'] === 'Divers Pro') {
            self::assertStringContainsString('<option value="Divers Pro" selected>', $r->body);
        }
        if ($form['recurrence_unit'] === 'day') {
            self::assertStringContainsString('<option value="day" selected>', $r->body);
        }
    }

    public function testRecurrenceWithoutDueDateIsShownUnderBothFields(): void
    {
        $r = $this->post('/tasks', ['title' => 'T', 'project' => 'Divers Pro', 'due_date' => '', 'recurrence_interval' => '1', 'recurrence_unit' => 'week']);
        self::assertSame(1, substr_count($r->body, 'id="e-due_date"'));
        self::assertSame(1, substr_count($r->body, 'id="e-recurrence"'));
        self::assertSame(1, substr_count($r->body, '<li><a href="#f-due_date">'), 'une seule entrée au résumé');
        self::assertStringContainsString('<option value="week" selected>', $r->body);
    }

    public function testPortalSpecificMessagesDoNotLeakTechnicalCodes(): void
    {
        $r = $this->post('/tasks', ['title' => 'T', 'project' => 'Divers Pro', 'due_date' => '2026-02-30']);
        self::assertStringContainsString('Date invalide.', $r->body);
        self::assertStringNotContainsString('AAAA-MM-JJ', $r->body);
        $r = $this->post('/tasks', ['title' => 'T', 'project' => 'Divers Pro', 'due_date' => '2026-01-01', 'recurrence_interval' => '2', 'recurrence_unit' => 'fortnight']);
        self::assertStringNotContainsString('day, week', $r->body);
    }

    public function testForgedExtraFieldsCannotReachTheUseCase(): void
    {
        $this->assertRedirect($this->post('/tasks', ['title' => 'T', 'project' => 'Divers Pro', 'status' => 'done', 'required_actions' => [1], 'id' => '99', 'completed_at' => '2026-01-01']), '/tasks/1');
        $task = $this->service->get(1);
        self::assertSame('todo', $task['status']);
        self::assertNull($task['completed_at']);
        self::assertSame([], $task['required_actions']);
    }

    // ----- Modification -----

    public function testUpdateChangesAndClearsOptionalFields(): void
    {
        $t = $this->seed(['title' => 'Avant', 'due_date' => '2026-03-01', 'estimated_minutes' => 30, 'description' => 'd']);
        $this->assertRedirect($this->post('/tasks/' . $t['id'], ['title' => 'Après', 'project' => 'Divers Perso', 'due_date' => '', 'recurrence_interval' => '', 'recurrence_unit' => '', 'estimated_minutes' => '', 'description' => '']), '/tasks/' . $t['id']);
        $after = $this->service->get($t['id']);
        self::assertSame('Après', $after['title']);
        self::assertSame('Perso', $after['category']);
        self::assertNull($after['due_date']);
        self::assertNull($after['estimated_minutes']);
        self::assertNull($after['description']);
    }

    public function testEditFormIsPrefilledWithEveryField(): void
    {
        $t = $this->seed(['title' => 'Complète "x"', 'project' => 'Divers Perso', 'due_date' => '2026-03-01', 'recurrence' => ['interval' => 3, 'unit' => 'week'], 'estimated_minutes' => 45, 'description' => "l1\nl2"]);
        $body = $this->get('/tasks/' . $t['id'] . '/edit')->body;
        foreach ([
            'value="Complète &quot;x&quot;"', '<option value="Divers Perso" selected>', 'value="2026-03-01"', 'name="recurrence_interval" min="1" step="1" value="3"',
            '<option value="week" selected>', 'name="estimated_minutes" min="1" step="1" value="45"', ">l1\nl2</textarea>",
            'action="/tasks/' . $t['id'] . '"', 'maxlength="255"', '255 caractères au plus',
        ] as $expected) {
            self::assertStringContainsString($expected, $body);
        }
    }

    public function testClearingDueDateOfRecurringTaskIsRefused(): void
    {
        $t = $this->seed(['title' => 'Périodique', 'due_date' => '2026-03-01', 'recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $before = $this->snapshot();
        $r = $this->post('/tasks/' . $t['id'], ['title' => 'Périodique', 'project' => 'Divers Pro', 'due_date' => '', 'recurrence_interval' => '1', 'recurrence_unit' => 'month', 'estimated_minutes' => '', 'description' => '']);
        $this->assertHtml($r, 422);
        self::assertStringContainsString('Une tâche périodique exige une date butée.', $r->body);
        self::assertSame($before, $this->snapshot(), 'tâche inchangée');
        self::assertStringContainsString('name="recurrence_interval" min="1" step="1" value="1"', $r->body);
    }

    public function testUpdateRefusalKeepsSubmittedInputAndTaskIntact(): void
    {
        $t = $this->seed(['title' => 'Originale']);
        $before = $this->snapshot();
        $r = $this->post('/tasks/' . $t['id'], ['title' => '', 'project' => 'Divers Perso', 'description' => 'saisie conservée']);
        $this->assertHtml($r, 422);
        self::assertStringContainsString('>saisie conservée</textarea>', $r->body);
        self::assertStringContainsString('<option value="Divers Perso" selected>', $r->body);
        self::assertStringContainsString('action="/tasks/' . $t['id'] . '"', $r->body);
        self::assertSame($before, $this->snapshot());
    }

    public function testUpdateWithoutChangeKeepsUpdatedAt(): void
    {
        $t = $this->seed(['title' => 'Stable']);
        $this->pdo->exec("UPDATE tasks SET updated_at = '2020-01-01T00:00:00Z'");
        $before = $this->snapshot();
        $this->assertRedirect($this->post('/tasks/' . $t['id'], ['title' => 'Stable', 'project' => 'Divers Pro']), '/tasks/' . $t['id']);
        self::assertSame($before, $this->snapshot());
    }

    public function testUpdatePreservesStatusAndLinks(): void
    {
        $req = $this->seed(['title' => 'Requise']);
        $t = $this->seed(['title' => 'Principale']);
        $this->service->addRequiredAction($t['id'], $req['id']);
        $this->service->changeStatus($t['id'], 'in_progress');
        $this->assertRedirect($this->post('/tasks/' . $t['id'], ['title' => 'Renommée', 'project' => 'Divers Pro']), '/tasks/' . $t['id']);
        $after = $this->service->get($t['id']);
        self::assertSame('in_progress', $after['status']);
        self::assertCount(1, $after['required_actions']);
    }

    // ----- Suppression -----

    public function testDeleteRefusedWhenRequiredShowsDependentsThenSucceedsAfterUnlink(): void
    {
        $req = $this->seed(['title' => 'Requise']);
        $d1 = $this->seed(['title' => 'Dépendante <b>1</b>']);
        $d2 = $this->seed(['title' => 'Dépendante 2']);
        $this->service->addRequiredAction($d1['id'], $req['id']);
        $this->service->addRequiredAction($d2['id'], $req['id']);
        $before = $this->snapshot();

        $r = $this->post('/tasks/' . $req['id'] . '/delete');
        $this->assertHtml($r, 409);
        self::assertStringContainsString('Suppression impossible : cette tâche est requise par :', $r->body);
        self::assertStringContainsString('<a href="/tasks/' . $d1['id'] . '">Dépendante &lt;b&gt;1&lt;/b&gt;</a>', $r->body);
        self::assertStringContainsString('<a href="/tasks/' . $d2['id'] . '">Dépendante 2</a>', $r->body);
        self::assertStringContainsString('name="_csrf"', $r->body);
        self::assertSame($before, $this->snapshot());
        self::assertSame(2, $this->links());

        $this->service->removeRequiredAction($d1['id'], $req['id']);
        $this->service->removeRequiredAction($d2['id'], $req['id']);
        $this->assertRedirect($this->post('/tasks/' . $req['id'] . '/delete'), '/tasks');
        self::assertSame(2, $this->rows());
    }

    public function testDeleteOfDependentTaskRemovesItsLinksOnly(): void
    {
        $req = $this->seed(['title' => 'Requise']);
        $dep = $this->seed(['title' => 'Dépendante']);
        $this->service->addRequiredAction($dep['id'], $req['id']);
        $this->assertRedirect($this->post('/tasks/' . $dep['id'] . '/delete'), '/tasks');
        self::assertSame(1, $this->rows());
        self::assertSame(0, $this->links());
    }

    public function testDeleteConfirmationNamesTheTaskAndOffersCancel(): void
    {
        $t = $this->seed(['title' => 'À confirmer']);
        $body = $this->get('/tasks/' . $t['id'] . '/delete')->body;
        self::assertStringContainsString('<strong>À confirmer</strong>', $body);
        self::assertStringContainsString('Supprimer définitivement', $body);
        self::assertStringContainsString('<a href="/tasks/' . $t['id'] . '">Annuler</a>', $body);
        self::assertStringContainsString('action="/tasks/' . $t['id'] . '/delete"', $body);
        self::assertStringNotContainsString('Suppression impossible', $body);
    }

    // ----- 404 -----

    public function testUnknownAndInvalidIdsAre404Html(): void
    {
        $this->seed();
        foreach (['/tasks/999', '/tasks/999/edit', '/tasks/999/delete'] as $path) {
            $r = $this->get($path);
            $this->assertHtml($r, 404);
            self::assertStringContainsString('Tâche introuvable.', $r->body);
        }
        foreach (['/tasks/999', '/tasks/999/delete'] as $path) {
            $this->assertHtml($this->post($path, ['title' => 'x', 'project' => 'Divers Pro']), 404);
        }
        foreach (['/tasks/abc', '/tasks/0', '/tasks/-1', '/tasks/1.5', '/tasks/99999999999999999999/edit'] as $path) {
            $this->assertHtml($this->get($path), 404);
        }
        self::assertSame(1, $this->rows());
    }

    // ----- Échappement -----

    public function testScriptTagsAreRenderedAsTextEverywhere(): void
    {
        $xss = self::XSS;
        $attr = 'x" onfocus="alert(1)" a="';
        $req = $this->seed(['title' => $xss, 'description' => $xss]);
        $main = $this->seed(['title' => $attr, 'description' => $xss]);
        $this->service->addRequiredAction($main['id'], $req['id']);

        $escapedXss = '&lt;script&gt;alert(1)&lt;/script&gt;';
        $pages = [
            'liste' => $this->get('/tasks')->body,
            'détail' => $this->get('/tasks/' . $main['id'])->body,
            'détail requise' => $this->get('/tasks/' . $req['id'])->body,
            'formulaire de modification' => $this->get('/tasks/' . $req['id'] . '/edit')->body,
            'confirmation' => $this->get('/tasks/' . $req['id'] . '/delete')->body,
            'refus de suppression' => $this->post('/tasks/' . $req['id'] . '/delete')->body,
            'saisie refusée' => $this->post('/tasks', ['title' => '', 'project' => 'Divers Pro', 'description' => $xss, 'due_date' => $xss])->body,
        ];
        foreach ($pages as $name => $body) {
            self::assertStringNotContainsString('<script', $body, $name);
            self::assertStringNotContainsString('onfocus="alert', $body, $name);
            self::assertStringContainsString($escapedXss, $body, $name);
        }
        $editAttr = $this->get('/tasks/' . $main['id'] . '/edit')->body;
        self::assertStringContainsString('value="x&quot; onfocus=&quot;alert(1)&quot; a=&quot;"', $editAttr);
        self::assertStringNotContainsString('onfocus="alert', $editAttr);

        // Message flash : titre dans « Tâche « … » supprimée. ».
        $other = $this->seed(['title' => $xss]);
        $this->post('/tasks/' . $other['id'] . '/delete');
        $after = $this->get('/tasks')->body;
        self::assertStringContainsString('Tâche « ' . $escapedXss . ' » supprimée.', $after);
        self::assertStringNotContainsString('<script', $after);
    }

    // ----- Sécurité de la garde -----

    public function testPostWithoutOrWithBadTokenIsRefusedAndWritesNothing(): void
    {
        $t = $this->seed(['title' => 'Intacte']);
        $before = $this->snapshot();
        $form = ['title' => 'Piraté', 'project' => 'Divers Perso'];
        foreach ([
            'sans jeton' => [],
            'jeton vide' => ['_csrf' => ''],
            'jeton faux' => ['_csrf' => str_repeat('0', 64)],
            'jeton tableau' => ['_csrf' => ['x']],
        ] as $label => $extra) {
            foreach (['/tasks', '/tasks/' . $t['id'], '/tasks/' . $t['id'] . '/delete'] as $path) {
                $r = $this->post($path, $form + $extra, withToken: false);
                $this->assertHtml($r, 403);
                self::assertStringContainsString('Requête refusée. Rechargez la page et recommencez.', $r->body, "$label $path");
            }
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $this->rows());
    }

    public function testForeignOriginIsRefusedAndSameOriginAccepted(): void
    {
        $before = $this->snapshot();
        foreach (['http://evil.example', 'http://127.0.0.1:9999', 'null', 'http://127.0.0.1', 'ftp://127.0.0.1:8080', 'http://evil.example:8080'] as $origin) {
            $r = $this->post('/tasks', ['title' => 'X', 'project' => 'Divers Pro'], ['origin' => $origin]);
            self::assertSame(403, $r->status, $origin);
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame(303, $this->post('/tasks', ['title' => 'X', 'project' => 'Divers Pro'], ['origin' => 'http://127.0.0.1:8080'])->status);
        self::assertSame(303, $this->post('/tasks', ['title' => 'Z', 'project' => 'Divers Pro'])->status, 'Origin absent accepté (jeton exigé)');
    }

    public function testEveryGuardRefusalExplainsWhyAndWhatToDo(): void
    {
        $form = ['title' => 'Première', 'project' => 'Divers Pro'];
        $cases = [
            'jeton absent' => [$this->post('/tasks', $form, withToken: false), 'Le formulaire est périmé ou la session a expiré'],
            'origine nulle' => [$this->post('/tasks', $form, ['origin' => 'null']), 'ne provient pas du portail lui-même'],
            'origine étrangère' => [$this->post('/tasks', $form, ['origin' => 'http://evil.example']), 'ne provient pas du portail lui-même'],
            'hôte inconnu' => [$this->kernel->handle(new Request('GET', '/tasks', ['host' => 'evil.example'])), 'refuse cette adresse'],
        ];
        foreach ($cases as $label => [$r, $reason]) {
            $this->assertHtml($r, 403);
            self::assertStringContainsString($reason, $r->body, $label);
            self::assertStringContainsString('Rien n&#039;a été enregistré', $r->body, $label);
            self::assertStringNotContainsString('PORTAL_ALLOWED_HOSTS', $r->body, $label);
            self::assertStringNotContainsString('evil.example', $r->body, $label);
            self::assertStringContainsString('<h1>', $r->body, $label);
        }
        self::assertSame(0, $this->rows());
    }

    public function testHostOutsideAllowedListIsRefused(): void
    {
        $this->seed(['title' => 'Secrète']);
        foreach (['evil.example', 'evil.example:8080', '127.0.0.1.evil.example'] as $host) {
            foreach (['/tasks', '/tasks/1', '/tasks/1/edit', '/tasks/new', '/tasks/1/delete'] as $path) {
                $r = $this->kernel->handle(new Request('GET', $path, ['host' => $host]));
                self::assertSame(403, $r->status, "$host $path");
                self::assertStringNotContainsString('Secrète', $r->body);
            }
            $r = $this->kernel->handle(new Request('POST', '/tasks/1/delete', ['host' => $host], '', [], ['_csrf' => $this->session->csrfToken()]));
            self::assertSame(403, $r->status);
        }
        self::assertSame(1, $this->rows());
    }

    public function testConfiguredHostIsAccepted(): void
    {
        $config = new \Actions\Infrastructure\Config('test', $this->config->dbHost, $this->config->dbPort, $this->config->dbName, $this->config->dbUser, $this->config->dbPassword, [], ['actions.example']);
        $kernel = Bootstrap::kernel($config, $this->session);
        self::assertSame(200, $kernel->handle(new Request('GET', '/tasks', ['host' => 'Actions.Example:443']))->status);
        self::assertSame(403, $kernel->handle(new Request('GET', '/tasks', ['host' => '127.0.0.1:8080']))->status, 'la liste configurée remplace le défaut');
    }

    public function testGetNeverWrites(): void
    {
        $t = $this->seed(['title' => 'Intacte']);
        $before = $this->snapshot();
        foreach (['/tasks', '/tasks/new', '/tasks/1', '/tasks/1/edit', '/tasks/1/delete'] as $path) {
            $this->get($path, ['title' => 'x', 'project' => 'Divers Perso', '_csrf' => $this->session->csrfToken(), 'status' => 'done', 'hide_done' => '1']);
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $this->rows());
        self::assertSame(405, $this->kernel->handle(new Request('PUT', '/tasks/' . $t['id'], ['host' => self::HOST]))->status);
        self::assertSame(405, $this->kernel->handle(new Request('DELETE', '/tasks/' . $t['id'], ['host' => self::HOST]))->status);
    }

    public function testSecurityHeadersOnHtmlResponses(): void
    {
        $this->seed();
        foreach ([$this->get('/tasks'), $this->get('/tasks/new'), $this->get('/tasks/1'), $this->get('/tasks/999'), $this->post('/tasks', [], withToken: false)] as $r) {
            self::assertSame("default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
            self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
            self::assertSame('same-origin', $r->headers['Referrer-Policy'], 'no-referrer ferait envoyer Origin: null par des navigateurs');
            self::assertSame('no-store', $r->headers['Cache-Control']);
        }
    }

    public function testFormsCarryTokenAndLabels(): void
    {
        $this->seed();
        foreach (['/tasks/new', '/tasks/1/edit', '/tasks/1/delete'] as $path) {
            self::assertMatchesRegularExpression('/<form method="post"[^>]*>\s*<input type="hidden" name="_csrf" value="[0-9a-f]{64}">/', $this->get($path)->body, $path);
        }
        $form = $this->get('/tasks/new')->body;
        foreach (['title', 'project', 'due_date', 'recurrence_interval', 'recurrence_unit', 'estimated_minutes', 'description'] as $field) {
            self::assertStringContainsString('<label for="f-' . $field . '"', $form);
            self::assertStringContainsString('id="f-' . $field . '"', $form);
        }
        self::assertStringContainsString('<option value="" selected>Choisir…</option>', $form);
        self::assertStringContainsString('type="date"', $form);
        self::assertStringNotContainsString('name="status"', $form);
        self::assertStringNotContainsString('required_actions', $form);
        // Le filtre de liste est un GET (adresse partageable, pas de jeton).
        self::assertStringContainsString('<form method="get" action="/tasks"', $this->get('/tasks')->body);
        self::assertStringNotContainsString('_csrf', $this->get('/tasks')->body);
    }

    // ----- Erreurs internes -----

    private function portalWith(\Closure $connect): PortalAdapter
    {
        return new PortalAdapter(\Actions\Tests\Support\Services::tasks($connect), \Actions\Tests\Support\Services::projects($connect), \Actions\Tests\Support\Services::overview($connect), $this->session, ['127.0.0.1']);
    }

    public function testUnexpectedPdoErrorGivesGenericPageWithoutDetail(): void
    {
        $portal = $this->portalWith(static function (): \PDO {
            $pdo = new class ('sqlite::memory:') extends \PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    throw new \PDOException('SQLSTATE[XX000]: secret_table colonne password=hunter2');
                }
            };

            return $pdo;
        });
        $r = $portal->handle(new Request('GET', '/tasks', ['host' => '127.0.0.1']));
        $this->assertHtml($r, 500);
        self::assertStringContainsString('Erreur interne.', $r->body);
        foreach (['SQLSTATE', 'secret_table', 'hunter2', 'PDO', 'Stack trace', '.php'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
        $r = $portal->handle(new Request('POST', '/tasks', ['host' => '127.0.0.1'], '', [], ['_csrf' => $this->session->csrfToken(), 'title' => 'a', 'project' => 'Divers Pro']));
        self::assertSame(500, $r->status);
        self::assertStringNotContainsString('SQLSTATE', $r->body);
    }

    public function testUnreachableDatabaseGives503WithoutDetail(): void
    {
        $portal = $this->portalWith(static function (): \PDO {
            throw new \PDOException('connection to server at "10.1.2.3", port 5432 failed: password authentication failed for user "secret"');
        });
        $r = $portal->handle(new Request('GET', '/tasks/1', ['host' => '127.0.0.1']));
        $this->assertHtml($r, 503);
        self::assertStringContainsString('Service indisponible.', $r->body);
        foreach (['10.1.2.3', 'secret', 'password', '5432'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
    }

    public function testRootRedirectsAndLeavesRestAndMcpUntouched(): void
    {
        $r = $this->get('/');
        self::assertSame(302, $r->status);
        self::assertSame('/tasks', $r->headers['Location']);
        self::assertSame(200, $this->kernel->handle(new Request('GET', '/api/v1/health'))->status);
        self::assertSame(200, $this->kernel->handle(new Request('GET', '/api/v1/tasks'))->status);
    }
}
