<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\ProjectService;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Tests\Support\ArraySession;
use PHPUnit\Framework\Attributes\DataProvider;

/** Portail des projets (gestion, vue projet, synthèse, matrice) : Kernel -> PortalAdapter -> services -> PostgreSQL réel. */
final class PortalProjectsTest extends DbTestCase
{
    private const HOST = '127.0.0.1:8080';
    private const XSS = '<script>alert(1)</script>';

    private Kernel $kernel;
    private ArraySession $session;
    private TaskService $tasks;
    private ProjectService $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->session = new ArraySession();
        $this->kernel = Bootstrap::kernel($this->config, $this->session, static fn (): string => '2026-10-18');
        $this->tasks = Bootstrap::taskService($this->config);
        $this->projects = Bootstrap::projectService($this->config);
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

        return $this->kernel->handle(new Request('POST', $path, ['host' => self::HOST] + $headers, '', [], $form));
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
        self::assertSame('', $r->body);
    }

    private static function text(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    private function projectCount(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM projects')->fetchColumn();
    }

    private function names(): string
    {
        return (string) json_encode($this->pdo->query('SELECT id, name, category FROM projects ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function id(string $name): int
    {
        return $this->projectId($name);
    }

    /** @param array<string,mixed> $extra */
    private function seed(string $title, string $project = 'OPF', array $extra = []): array
    {
        return $this->tasks->create($extra + ['title' => $title, 'project' => $project]);
    }

    // ----- Liste -----

    public function testListShowsHomeProjectsWithCategoryAndTaskCount(): void
    {
        $this->seed('A');
        $this->seed('B');
        $r = $this->get('/projects');
        $this->assertHtml($r, 200);
        self::assertStringContainsString('<title>Projets — Actions</title>', $r->body);
        self::assertStringContainsString('href="/projects"', $r->body, 'lien de navigation');
        $text = self::text($r->body);
        foreach (['Divers Pro', 'Divers Perso', 'MS-HR', 'Jardin/Maison', 'OPF', 'Erendil', 'Nouveau projet'] as $name) {
            self::assertStringContainsString($name, $text);
        }
        self::assertMatchesRegularExpression('#<a href="/projects/' . $this->id('OPF') . '">OPF</a></td>\s*<td>Perso</td>\s*<td>2</td>#', $r->body);
        self::assertMatchesRegularExpression('#<a href="/projects/' . $this->id('MS-HR') . '">MS-HR</a></td>\s*<td>Pro</td>\s*<td>0</td>#', $r->body);
    }

    // ----- Création -----

    public function testCreateFormThenCreation(): void
    {
        $form = $this->get('/projects/new');
        $this->assertHtml($form, 200);
        self::assertStringContainsString('<option value="" selected>Choisir…</option>', $form->body);
        self::assertStringContainsString('for="f-name"', $form->body);
        self::assertStringContainsString('for="f-category"', $form->body);
        self::assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $m));

        $before = $this->projectCount();
        $r = $this->post('/projects', ['_csrf' => $m[1], 'name' => 'Nouveau', 'category' => 'Pro'], withToken: false);
        $this->assertRedirect($r, '/projects');
        self::assertSame($before + 1, $this->projectCount());
        $list = $this->get('/projects');
        self::assertStringContainsString('Projet « Nouveau » créé.', $list->body);
        self::assertStringContainsString('role="status"', $list->body);
        // visible dans la liste déroulante du formulaire de tâche
        self::assertStringContainsString('<option value="Nouveau"', $this->get('/tasks/new')->body);
    }

    /** @return array<string,array{string,mixed,int,string}> */
    public static function invalidCreations(): array
    {
        return [
            'nom vide' => ['', 'Pro', 422, 'project_name_blank'],
            'nom de blancs' => ['   ', 'Pro', 422, 'project_name_blank'],
            '256 caractères' => [str_repeat('é', 256), 'Pro', 422, 'project_name_too_long'],
            'catégorie vide' => ['X', '', 422, 'category_invalid'],
            'catégorie en minuscules' => ['X', 'pro', 422, 'category_invalid'],
            'catégorie inconnue' => ['X', 'Autre', 422, 'category_invalid'],
            'doublon, casse ignorée' => ['opf', 'Perso', 409, 'project_name_taken'],
            'doublon exact' => ['OPF', 'Pro', 409, 'project_name_taken'],
        ];
    }

    #[DataProvider('invalidCreations')]
    public function testInvalidCreationIsRefusedNearTheFieldAndSameAsRest(string $name, string $category, int $status, string $code): void
    {
        $snapshot = $this->names();
        $r = $this->post('/projects', ['name' => $name, 'category' => $category]);
        $this->assertHtml($r, $status);
        self::assertSame($snapshot, $this->names(), 'rien créé');
        $field = $code === 'category_invalid' ? 'category' : 'name';
        self::assertStringContainsString('id="e-' . $field . '"', $r->body, 'message près du champ');
        self::assertStringContainsString('aria-invalid="true"', $r->body);
        self::assertStringContainsString('href="#f-' . $field . '"', $r->body, 'résumé relié au champ');
        self::assertStringContainsString('value="' . htmlspecialchars($name, ENT_QUOTES) . '"', $r->body, 'saisie conservée');
        // Parité : REST répond le même code et le même statut.
        $rest = $this->kernel->handle(new Request('POST', '/api/v1/projects', [], json_encode(['name' => $name, 'category' => $category], JSON_THROW_ON_ERROR)));
        self::assertSame($status, $rest->status, $rest->body);
        self::assertSame($code, json_decode($rest->body, true)['error']['code']);
        self::assertSame($snapshot, $this->names());
    }

    public function testForgedArrayFieldGivesTypeError(): void
    {
        $r = $this->post('/projects', ['name' => ['a'], 'category' => 'Pro']);
        $this->assertHtml($r, 422);
        self::assertStringContainsString('Valeur invalide.', $r->body);
        self::assertStringNotContainsString('Array', $r->body);
    }

    // ----- Modification -----

    public function testEditFormWarnsAboutTaskCount(): void
    {
        $this->seed('A');
        $this->seed('B');
        $r = $this->get('/projects/' . $this->id('OPF') . '/edit');
        $this->assertHtml($r, 200);
        self::assertStringContainsString('value="OPF"', $r->body);
        self::assertStringContainsString('<option value="Perso" selected>', $r->body);
        self::assertStringContainsString('Ce projet contient 2 tâche(s) : changer sa catégorie change celle de ces tâches.', $r->body);
    }

    public function testRenameIsFollowedByTasksAndShownEverywhere(): void
    {
        $t = $this->seed('Suivie');
        $id = $this->id('OPF');
        $this->assertRedirect($this->post('/projects/' . $id, ['name' => 'Renommé', 'category' => 'Perso']), '/projects');
        $list = $this->get('/projects');
        self::assertStringContainsString('Projet enregistré.', $list->body);
        self::assertStringNotContainsString('tâche(s) concernée', $list->body, 'catégorie inchangée');
        self::assertStringContainsString('Renommé', $list->body);
        self::assertSame('Renommé', $this->tasks->get($t['id'])['project']);
        self::assertStringContainsString('Renommé', $this->get('/tasks/' . $t['id'])->body);
        self::assertStringContainsString('<option value="Renommé"', $this->get('/tasks/new')->body);
        self::assertStringContainsString('<h1>Renommé</h1>', $this->get('/projects/' . $id)->body);
    }

    public function testSaveWithoutChangeIsNotAnError(): void
    {
        $before = $this->names();
        $this->assertRedirect($this->post('/projects/' . $this->id('OPF'), ['name' => 'OPF', 'category' => 'Perso']), '/projects');
        self::assertSame($before, $this->names());
    }

    public function testRenameChangingOnlyTheCaseIsAccepted(): void
    {
        $this->assertRedirect($this->post('/projects/' . $this->id('OPF'), ['name' => 'opf', 'category' => 'Perso']), '/projects');
        self::assertSame('opf', $this->projects->get($this->id('opf'))['name']);
    }

    public function testRenameToAnExistingNameIsRefused(): void
    {
        $before = $this->names();
        $r = $this->post('/projects/' . $this->id('OPF'), ['name' => 'erendil', 'category' => 'Perso']);
        $this->assertHtml($r, 409);
        self::assertStringContainsString('id="e-name"', $r->body);
        self::assertStringContainsString('value="erendil"', $r->body);
        self::assertSame($before, $this->names());
    }

    public function testInvalidRenameIsRefusedAndKeepsInput(): void
    {
        $before = $this->names();
        $r = $this->post('/projects/' . $this->id('OPF'), ['name' => '  ', 'category' => 'Pro']);
        $this->assertHtml($r, 422);
        self::assertStringContainsString('id="e-name"', $r->body);
        self::assertStringContainsString('<option value="Pro" selected>', $r->body);
        self::assertSame($before, $this->names());
    }

    public function testCategoryChangeShowsTheNumberOfTasksAndTasksFollow(): void
    {
        $a = $this->seed('A');
        $b = $this->seed('B');
        $this->tasks->changeStatus($b['id'], 'in_progress');
        $this->tasks->changeStatus($b['id'], 'done');
        $this->assertRedirect($this->post('/projects/' . $this->id('OPF'), ['name' => 'OPF', 'category' => 'Pro']), '/projects');
        self::assertStringContainsString('Catégorie changée : 2 tâche(s) concernée(s).', $this->get('/projects')->body);
        self::assertSame('Pro', $this->tasks->get($a['id'])['category']);
        self::assertSame('Pro', $this->tasks->get($b['id'])['category'], 'y compris Terminée');
    }

    // ----- Suppression -----

    public function testDeleteEmptyProjectWithConfirmation(): void
    {
        $id = $this->id('Erendil');
        $confirm = $this->get('/projects/' . $id . '/delete');
        $this->assertHtml($confirm, 200);
        self::assertStringContainsString('<strong>Erendil</strong>', $confirm->body);
        self::assertSame(6, $this->projectCount(), 'le GET ne supprime rien');
        $this->assertRedirect($this->post('/projects/' . $id . '/delete'), '/projects');
        self::assertSame(5, $this->projectCount());
        self::assertStringContainsString('Projet « Erendil » supprimé.', $this->get('/projects')->body);
        self::assertSame(404, $this->get('/projects/' . $id)->status);
    }

    public function testDeleteUsedProjectIsRefusedWithCountAndStaysIntact(): void
    {
        $this->seed('A');
        $this->seed('B');
        $id = $this->id('OPF');
        $page = $this->get('/projects/' . $id . '/delete');
        $this->assertHtml($page, 200);
        $r = $this->post('/projects/' . $id . '/delete');
        $this->assertHtml($r, 409);
        self::assertStringContainsString('Suppression impossible : ce projet contient 2 tâche(s).', $r->body);
        self::assertStringContainsString('href="/tasks?project=OPF"', $r->body);
        self::assertSame(6, $this->projectCount());
        self::assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn());
        // Parité REST
        $rest = $this->kernel->handle(new Request('DELETE', '/api/v1/projects/' . $id));
        self::assertSame(409, $rest->status);
        self::assertSame('project_in_use', json_decode($rest->body, true)['error']['code']);
    }

    // ----- Gardes -----

    public function testWritesAreGuarded(): void
    {
        $id = $this->id('Erendil');
        $writes = [
            ['/projects', ['name' => 'Z', 'category' => 'Pro']],
            ['/projects/' . $id, ['name' => 'Z', 'category' => 'Pro']],
            ['/projects/' . $id . '/delete', []],
        ];
        $before = $this->names();
        foreach ($writes as [$path, $form]) {
            $r = $this->post($path, $form, withToken: false);
            self::assertSame(403, $r->status, $path . ' sans jeton');
            $r = $this->post($path, $form + ['_csrf' => 'faux'], withToken: false);
            self::assertSame(403, $r->status, $path . ' jeton faux');
            $r = $this->post($path, $form, ['origin' => 'http://evil.example']);
            self::assertSame(403, $r->status, $path . ' origine étrangère');
            $r = $this->kernel->handle(new Request('POST', $path, ['host' => 'evil.example'], '', [], $form + ['_csrf' => $this->session->csrfToken()]));
            self::assertSame(403, $r->status, $path . ' hôte étranger');
        }
        self::assertSame($before, $this->names(), 'aucune écriture');
        // Aucune écriture par GET
        $this->get('/projects', ['name' => 'Z', 'category' => 'Pro']);
        $this->get('/projects/new', ['name' => 'Z', 'category' => 'Pro']);
        $this->get('/projects/' . $id . '/delete');
        self::assertSame($before, $this->names());
    }

    public function testUnknownRoutesAndMethods(): void
    {
        foreach (['/projects/999', '/projects/abc', '/projects/0', '/projects/999/edit', '/projects/999/delete', '/projects/-1', '/projects/1/zzz', '/projects/1/edit/x'] as $path) {
            $r = $this->get($path);
            self::assertSame(404, $r->status, $path);
            self::assertStringNotContainsString('Exception', $r->body);
        }
        self::assertStringContainsString('Projet introuvable', $this->get('/projects/999')->body);
        self::assertSame(404, $this->post('/projects/999', ['name' => 'Z', 'category' => 'Pro'])->status);
        self::assertSame(404, $this->post('/projects/999/delete')->status);
        $r = $this->kernel->handle(new Request('DELETE', '/projects', ['host' => self::HOST]));
        self::assertSame(405, $r->status);
        self::assertSame('GET, POST', $r->headers['Allow']);
        self::assertSame('GET', $this->kernel->handle(new Request('POST', '/projects/new', ['host' => self::HOST]))->headers['Allow']);
        self::assertSame('GET', $this->kernel->handle(new Request('PUT', '/projects/' . $this->id('OPF') . '/edit', ['host' => self::HOST]))->headers['Allow']);
        self::assertSame('GET, POST', $this->kernel->handle(new Request('DELETE', '/projects/' . $this->id('OPF') . '/delete', ['host' => self::HOST]))->headers['Allow']);
    }

    public function testNameIsEscapedEverywhere(): void
    {
        $this->assertRedirect($this->post('/projects', ['name' => self::XSS, 'category' => 'Pro']), '/projects');
        $id = $this->id(self::XSS);
        $this->seed('T', self::XSS);
        $pages = [
            'flash' => $this->get('/projects'),
            'liste' => $this->get('/projects'),
            'vue' => $this->get('/projects/' . $id),
            'édition' => $this->get('/projects/' . $id . '/edit'),
            'confirmation' => $this->get('/projects/' . $id . '/delete'),
            'refus' => $this->post('/projects/' . $id . '/delete'),
            'tâches' => $this->get('/tasks'),
            'détail tâche' => $this->get('/tasks/1'),
        ];
        foreach ($pages as $label => $r) {
            self::assertStringNotContainsString(self::XSS, $r->body, $label);
            self::assertStringContainsString('&lt;script&gt;', $r->body, $label);
        }
        self::assertStringContainsString('href="/tasks?project=%3Cscript%3Ealert%281%29%3C%2Fscript%3E"', $pages['vue']->body);
        $this->assertRedirect($this->post('/projects', ['name' => '<b>x</b>', 'category' => 'Pro']), '/projects');
        self::assertStringNotContainsString('<b>x</b>', $this->get('/projects')->body);
        $created = $this->post('/projects', ['name' => '<i>flash</i>', 'category' => 'Pro']);
        $this->assertRedirect($created, '/projects');
        self::assertStringNotContainsString('<i>flash</i>', $this->get('/projects')->body);
    }

    public function testNameWithSlashDoesNotAffectRoutes(): void
    {
        $this->assertRedirect($this->post('/projects', ['name' => 'a/b?c#d', 'category' => 'Perso']), '/projects');
        $id = $this->id('a/b?c#d');
        $this->assertHtml($this->get('/projects/' . $id), 200);
    }

    // ----- Vue projet : synthèse -----

    public function testEmptyProjectView(): void
    {
        $r = $this->get('/projects/' . $this->id('Erendil'));
        $this->assertHtml($r, 200);
        self::assertStringContainsString('<title>Projet Erendil — Actions</title>', $r->body);
        self::assertStringContainsString('Ce projet ne contient aucune tâche.', $r->body);
        self::assertStringContainsString('href="/tasks?project=Erendil"', $r->body);
        self::assertStringContainsString('Situation au', $r->body);
        self::assertStringContainsString('18/10/2026', $r->body);
        foreach (['À faire', 'En cours', 'Terminée', 'Total'] as $label) {
            self::assertMatchesRegularExpression('#<th scope="row">' . $label . '</th><td>0</td>#', $r->body, $label);
        }
    }

    public function testSummaryCountsEachStateAndUpdatesOnReload(): void
    {
        $a = $this->seed('A');
        $b = $this->seed('B');
        $c = $this->seed('C');
        $d = $this->seed('D');
        $this->seed('Ailleurs', 'MS-HR');
        $this->tasks->changeStatus($b['id'], 'in_progress');
        foreach ([$c['id'], $d['id']] as $id) {
            $this->tasks->changeStatus($id, 'in_progress');
            $this->tasks->changeStatus($id, 'done');
        }
        $id = $this->id('OPF');
        $page = $this->get('/projects/' . $id)->body;
        self::assertStringNotContainsString('aucune tâche', $page);
        foreach (['À faire' => 1, 'En cours' => 1, 'Terminée' => 2, 'Total' => 4] as $label => $n) {
            self::assertMatchesRegularExpression('#<th scope="row">' . $label . '</th><td>' . $n . '</td>#', $page, $label);
        }
        $this->tasks->changeStatus($a['id'], 'in_progress');
        self::assertMatchesRegularExpression('#<th scope="row">À faire</th><td>0</td>#', $this->get('/projects/' . $id)->body);
        self::assertMatchesRegularExpression('#<th scope="row">En cours</th><td>2</td>#', $this->get('/projects/' . $id)->body);
    }

    // ----- Vue projet : matrice -----

    public function testMatrixIsAccessibleTableWithLinksAndOutsideCounts(): void
    {
        $retard = $this->seed('En retard', 'OPF', ['importance' => 'Critique', 'due_date' => '2026-10-01']);
        $mois = $this->seed('Dans un mois', 'OPF', ['importance' => 'Majeure', 'due_date' => '2026-11-17']);
        $this->seed('Lointaine', 'OPF', ['importance' => 'Mineure', 'due_date' => '2027-06-01']);
        $this->seed('Sans date', 'OPF', ['importance' => 'Critique']);
        $fin = $this->seed('Finie', 'OPF', ['importance' => 'Critique', 'due_date' => '2026-10-02']);
        $this->tasks->changeStatus($fin['id'], 'in_progress');
        $this->tasks->changeStatus($fin['id'], 'done');
        $this->seed('Autre projet', 'MS-HR', ['importance' => 'Critique', 'due_date' => '2026-10-18']);

        $r = $this->get('/projects/' . $this->id('OPF'));
        $this->assertHtml($r, 200);
        $html = $r->body;
        self::assertStringContainsString('<caption>', $html);
        foreach (["Moins d'une semaine", "Moins d'un mois", 'Au-delà'] as $col) {
            self::assertStringContainsString('<th scope="col">' . htmlspecialchars($col, ENT_QUOTES) . '</th>', $html);
        }
        foreach (['Critique', 'Majeure', 'Mineure'] as $row) {
            self::assertStringContainsString('<th scope="row">' . $row . '</th>', $html);
        }
        self::assertLessThan(strpos($html, '<th scope="row">Majeure</th>'), strpos($html, '<th scope="row">Critique</th>'));
        self::assertLessThan(strpos($html, '<th scope="row">Mineure</th>'), strpos($html, '<th scope="row">Majeure</th>'));
        self::assertStringContainsString('<strong>en retard</strong> : <a href="/tasks/' . $retard['id'] . '">En retard</a> (01/10/2026)', $html);
        self::assertStringContainsString('<a href="/tasks/' . $mois['id'] . '">Dans un mois</a>', $html);
        self::assertStringContainsString('Lointaine', $html);
        self::assertStringNotContainsString('Autre projet', $html);
        self::assertStringNotContainsString('>Finie<', $html, 'une Terminée n\'est que comptée');
        self::assertStringContainsString('Terminées (hors matrice) : 1', $html);
        self::assertStringContainsString('Sans date butée : 1', $html);
        self::assertStringContainsString('Sans date</a>', $html);
        self::assertStringContainsString('1 action(s)', $html);
        self::assertStringContainsString('Aucune', $html);
        self::assertSame(3, substr_count($html, '1 action(s)'), 'trois cellules renseignées');
    }

    // ----- Liens depuis les tâches -----

    public function testProjectNameLinksToTheProjectView(): void
    {
        $t = $this->seed('Liée');
        $id = $this->id('OPF');
        self::assertStringContainsString('<a href="/projects/' . $id . '">OPF</a>', $this->get('/tasks/' . $t['id'])->body);
        self::assertStringContainsString('<a href="/projects/' . $id . '">OPF</a>', $this->get('/tasks')->body);
        self::assertSame(200, $this->get('/projects/' . $id)->status);
    }
}
