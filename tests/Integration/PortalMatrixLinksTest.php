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

/**
 * Matrice à nombres cliquables : le nombre d'une case est égal au nombre de lignes de la liste ouverte par son lien
 * (PostgreSQL réel, date injectée au 2026-10-18).
 */
final class PortalMatrixLinksTest extends DbTestCase
{
    private const HOST = '127.0.0.1:8080';
    private const XSS = '<script>alert(1)</script>';
    private const TODAY = '2026-10-18';
    /** Lignes dans l'ordre d'affichage; colonnes late, week, month, later. */
    private const IMPORTANCES = ['Critique', 'Majeure', 'Mineure'];
    private const COLUMNS = ['late', 'week', 'month', 'later'];

    private Kernel $kernel;
    private ArraySession $session;
    private TaskService $tasks;
    private ProjectService $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->session = new ArraySession();
        $this->kernel = Bootstrap::kernel($this->config, $this->session, static fn (): string => self::TODAY);
        $this->tasks = Bootstrap::taskService($this->config);
        $this->projects = Bootstrap::projectService($this->config);
    }

    /** @param array<string,mixed> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, ['host' => self::HOST], '', $query));
    }

    /** @param array<string,mixed> $extra */
    private function seed(string $title, string $project, string $importance, ?string $due, array $extra = []): int
    {
        return $this->tasks->create($extra + ['title' => $title, 'project' => $project, 'importance' => $importance, 'due_date' => $due])['id'];
    }

    private function finish(int $id): void
    {
        $this->tasks->changeStatus($id, 'in_progress');
        $this->tasks->changeStatus($id, 'done');
    }

    /**
     * Jeu de référence du projet OPF (12 cases) : Critique 1/1/1/1, Majeure 0/1/0/1, Mineure 0/0/1/3.
     * Plus 2 Terminées (dont une en retard), 2 sans date (dont une En cours) et du bruit dans un autre projet.
     *
     * @return array<string,array<string,int>> comptes attendus [importance][colonne]
     */
    private function populate(): array
    {
        $this->seed('C-retard', 'OPF', 'Critique', '2026-10-17');
        $this->seed('C-jour', 'OPF', 'Critique', '2026-10-18');
        $this->seed('C-mois', 'OPF', 'Critique', '2026-10-26');
        $this->seed('C-loin', 'OPF', 'Critique', '2026-12-01');
        $this->seed('M-j6', 'OPF', 'Majeure', '2026-10-24');
        $this->seed('M-loin', 'OPF', 'Majeure', '2027-01-01');
        $this->seed('m-j7', 'OPF', 'Mineure', '2026-10-25');
        $this->seed('m-mois-exact', 'OPF', 'Mineure', '2026-11-18');
        $this->seed('m-loin1', 'OPF', 'Mineure', '2027-02-01');
        $this->seed('m-loin2', 'OPF', 'Mineure', '2027-03-01');
        $done1 = $this->seed('Finie-retard', 'OPF', 'Critique', '2026-10-01');
        $done2 = $this->seed('Finie-sans-date', 'OPF', 'Mineure', null);
        $this->finish($done1);
        $this->finish($done2);
        $this->seed('Sans-date-1', 'OPF', 'Critique', null);
        $sd2 = $this->seed('Sans-date-2', 'OPF', 'Majeure', null);
        $this->tasks->changeStatus($sd2, 'in_progress');
        // Bruit : mêmes critères, autre projet.
        $this->seed('Autre-1', 'MS-HR', 'Critique', '2026-10-18');
        $this->seed('Autre-2', 'MS-HR', 'Critique', null);

        return [
            'Critique' => ['late' => 1, 'week' => 1, 'month' => 1, 'later' => 1],
            'Majeure' => ['late' => 0, 'week' => 1, 'month' => 0, 'later' => 1],
            'Mineure' => ['late' => 0, 'week' => 0, 'month' => 1, 'later' => 3],
        ];
    }

    /**
     * Les 12 cases de la vue, dans l'ordre d'affichage : [nombre, href (décodé) ou null].
     *
     * @return list<array{0:int,1:?string}>
     */
    private static function cells(string $html): array
    {
        $count = preg_match_all('#<td class="num">\s*(?:<a href="([^"]*)">(\d+)<|<span>(\d+)<)#', $html, $m, PREG_SET_ORDER);
        $cells = [];
        foreach ($m as $row) {
            $cells[] = isset($row[2]) && $row[2] !== '' ? [(int) $row[2], html_entity_decode($row[1], ENT_QUOTES, 'UTF-8')] : [(int) $row[3], null];
        }
        self::assertSame(12, $count, 'douze cases');

        return $cells;
    }

    /** Liens « Terminées » et « Sans date butée » : [nombre, href ou null]. */
    private static function outside(string $html, string $label): array
    {
        self::assertSame(1, preg_match('#' . preg_quote($label, '#') . ' :\s*(?:<a class="num" href="([^"]*)">(\d+)<|<span class="num">(\d+)<)#u', $html, $m), $label);

        return isset($m[2]) && $m[2] !== '' ? [(int) $m[2], html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')] : [(int) $m[3], null];
    }

    /** Ouvre un lien de la vue tel qu'un navigateur le ferait (chemin + requête décodée). */
    private function open(string $href): Response
    {
        $parts = parse_url($href);
        self::assertIsArray($parts);
        parse_str($parts['query'] ?? '', $query);

        return $this->get($parts['path'], $query);
    }

    /** @return list<string> titres de la liste (lignes de tbody) */
    private static function titles(Response $r): array
    {
        preg_match_all('#<td class="title"><a href="/tasks/\d+">(.*?)</a></td>#', $r->body, $m);

        return array_map(static fn (string $t): string => html_entity_decode($t, ENT_QUOTES, 'UTF-8'), $m[1]);
    }

    private function overviewPage(string $project = 'OPF'): string
    {
        $r = $this->get('/projects/' . $this->projectId($project));
        self::assertSame(200, $r->status, $r->body);

        return $r->body;
    }

    // ----- Invariant nombre = lignes -----

    public function testEveryCellCountEqualsTheRowsOfItsLinkedList(): void
    {
        $expected = $this->populate();
        $cells = self::cells($this->overviewPage());
        $i = 0;
        foreach (self::IMPORTANCES as $importance) {
            foreach (self::COLUMNS as $column) {
                [$count, $href] = $cells[$i++];
                self::assertSame($expected[$importance][$column], $count, "$importance / $column : nombre attendu");
                if ($count === 0) {
                    self::assertNull($href, "$importance / $column : un 0 n'est pas un lien (E3)");
                    continue;
                }
                self::assertNotNull($href);
                $list = $this->open($href);
                self::assertSame(200, $list->status, $href);
                self::assertCount($count, self::titles($list), "$importance / $column : nombre = lignes de la liste");
            }
        }
        self::assertSame(4, count(array_filter($cells, static fn (array $c): bool => $c[0] === 0 && $c[1] === null)), 'quatre cases vides en texte');
    }

    public function testCellListsHoldExactlyTheTasksOfTheCell(): void
    {
        $this->populate();
        $cells = self::cells($this->overviewPage());
        self::assertSame(['C-retard'], self::titles($this->open((string) $cells[0][1])), 'Critique / en retard');
        self::assertSame(['C-jour'], self::titles($this->open((string) $cells[1][1])), 'Critique / semaine');
        self::assertSame(['C-mois'], self::titles($this->open((string) $cells[2][1])));
        self::assertSame(['M-j6'], self::titles($this->open((string) $cells[5][1])), 'J+6 : encore dans la semaine');
        self::assertSame(['m-j7'], self::titles($this->open((string) $cells[10][1])), 'J+7 : dans le mois');
        self::assertEqualsCanonicalizing(['m-mois-exact', 'm-loin1', 'm-loin2'], self::titles($this->open((string) $cells[11][1])), 'un mois exact : au-delà');
        foreach ($cells as [$count, $href]) {
            if ($href !== null) {
                $titles = self::titles($this->open($href));
                self::assertNotContains('Autre-1', $titles, 'un autre projet n\'est pas compté');
                self::assertNotContains('Finie-retard', $titles, 'une Terminée n\'est jamais dans une case');
            }
        }
    }

    public function testLinkTargetsTheCaseExactlyAndNothingElse(): void
    {
        $this->populate();
        $href = (string) self::cells($this->overviewPage())[0][1];
        $parts = parse_url($href);
        self::assertSame('/tasks', $parts['path']);
        parse_str($parts['query'], $query);
        self::assertSame(['project' => 'OPF', 'importance' => 'Critique', 'urgency' => 'late'], $query);
    }

    // ----- Hors matrice -----

    public function testOutsideCountsAreLinksWithTheSameCountAsTheList(): void
    {
        $this->populate();
        $html = $this->overviewPage();
        [$done, $doneHref] = self::outside($html, 'Terminées');
        [$noDate, $noDateHref] = self::outside($html, 'Sans date butée');
        self::assertSame(2, $done);
        self::assertSame(2, $noDate);
        self::assertEqualsCanonicalizing(['Finie-retard', 'Finie-sans-date'], self::titles($this->open((string) $doneHref)));
        $list = $this->open((string) $noDateHref);
        self::assertEqualsCanonicalizing(['Sans-date-1', 'Sans-date-2'], self::titles($list), 'non terminées, sans date; ni la Terminée sans date ni l\'autre projet');
        self::assertCount($noDate, self::titles($list));
        self::assertStringContainsString('Sans-date-2', $list->body);
        parse_str((string) parse_url((string) $noDateHref, PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('importance', $query, 'E4 : sans filtre d\'importance');
    }

    public function testSumOfCellsAndOutsideEqualsTheProjectTotal(): void
    {
        $this->populate();
        $html = $this->overviewPage();
        $sum = array_sum(array_column(self::cells($html), 0)) + self::outside($html, 'Terminées')[0] + self::outside($html, 'Sans date butée')[0];
        self::assertSame(14, $sum, 'V4/V5 : rien de perdu (10 en grille + 2 Terminées + 2 sans date = total du projet)');
        self::assertSame(count(self::titles($this->get('/tasks', ['project' => 'OPF']))), $sum);
    }

    public function testZeroOutsideCountsAreTextNotLinks(): void
    {
        $this->seed('Seule', 'OPF', 'Critique', '2026-10-18');
        $html = $this->overviewPage();
        self::assertSame([0, null], self::outside($html, 'Terminées'));
        self::assertSame([0, null], self::outside($html, 'Sans date butée'));
    }

    // ----- Vue : plus de titres, accessibilité -----

    public function testGridShowsNoTitleNoLateMentionAndAccessibleNames(): void
    {
        $this->populate();
        $html = $this->overviewPage();
        foreach (['C-retard', 'C-jour', 'M-j6', 'Sans-date-1', 'Finie-retard'] as $title) {
            self::assertStringNotContainsString($title, $html, 'plus aucun titre dans la vue projet');
        }
        self::assertStringNotContainsString('en retard</strong>', $html);
        self::assertStringContainsString('1<span class="visually-hidden"> action, importance Critique, échéance en retard</span>', $html);
        self::assertStringContainsString('1<span class="visually-hidden"> action, importance Majeure, échéance au-delà</span>', $html);
        self::assertStringContainsString('<span>0<span class="visually-hidden"> action, importance Majeure, échéance moins d&#039;un mois</span></span>', $html, 'le zéro reçoit le même texte masqué');
        foreach (['En retard', "Moins d'une semaine", "Moins d'un mois", 'Au-delà'] as $col) {
            self::assertStringContainsString('<th scope="col">' . htmlspecialchars($col, ENT_QUOTES) . '</th>', $html, 'en-têtes de colonne conservés');
        }
        foreach (self::IMPORTANCES as $importance) {
            self::assertStringContainsString('<th scope="row">' . $importance . '</th>', $html);
        }
        self::assertStringContainsString('Terminées :', $html);
    }

    public function testRenamedProjectKeepsLinksAndEquality(): void
    {
        $this->populate();
        $this->projects->update($this->projectId('OPF'), ['name' => 'OPF renommé']);
        $html = $this->overviewPage('OPF renommé');
        $cells = self::cells($html);
        self::assertStringContainsString('project=OPF%20renomm%C3%A9', html_entity_decode((string) $cells[0][1]), 'nom encodé dans le lien');
        foreach ($cells as [$count, $href]) {
            if ($href !== null) {
                self::assertCount($count, self::titles($this->open($href)));
            }
        }
        self::assertCount(1, self::titles($this->open((string) $cells[0][1])));
    }

    public function testProjectNameWithMarkupIsEscapedInViewLinksAndCriteria(): void
    {
        $this->projects->create(['name' => self::XSS, 'category' => 'Pro']);
        $this->seed('T', self::XSS, 'Critique', '2026-10-18');
        $r = $this->get('/projects/' . $this->projectId(self::XSS));
        self::assertSame(200, $r->status);
        self::assertStringNotContainsString(self::XSS, $r->body);
        $href = (string) self::cells($r->body)[1][1];
        self::assertStringNotContainsString('<', $href);
        self::assertStringContainsString('%3Cscript%3E', $href);
        $list = $this->open($href);
        self::assertSame(200, $list->status);
        self::assertSame(['T'], self::titles($list));
        self::assertStringNotContainsString(self::XSS, $list->body, 'critères et filtres échappés');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $list->body);
    }

    // ----- Liste filtrée : critères, retour, combinaisons -----

    public function testListStatesItsCriteriaAndOffersTheWayBack(): void
    {
        $this->populate();
        $r = $this->get('/tasks', ['project' => 'OPF', 'importance' => 'Critique', 'urgency' => 'week']);
        $text = html_entity_decode(strip_tags($r->body), ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString('projet OPF', $text);
        self::assertStringContainsString('importance Critique', $text);
        self::assertStringContainsString('échéance moins d\'une semaine', $text);
        self::assertStringContainsString('situation au 18/10/2026', $text);
        self::assertStringContainsString('<a href="/projects/' . $this->projectId('OPF') . '">Retour à la vue du projet</a>', $r->body);
        self::assertStringContainsString('<option value="week" selected>Moins d&#039;une semaine</option>', $r->body, 'le filtre reste modifiable et visible');
        $late = $this->get('/tasks', ['project' => 'OPF', 'importance' => 'Critique', 'urgency' => 'late']);
        self::assertStringContainsString('<option value="late" selected>En retard</option>', $late->body);
        self::assertSame(['C-retard'], self::titles($late));
        // Sans projet connu : pas de lien de retour.
        self::assertStringNotContainsString('Retour à la vue du projet', $this->get('/tasks', ['urgency' => 'week'])->body);
        $noDate = $this->get('/tasks', ['project' => 'OPF', 'no_due_date' => '1']);
        self::assertStringContainsString('sans date butée seulement', $noDate->body);
        self::assertMatchesRegularExpression('/<input type="checkbox" id="f-no-due-date" name="no_due_date" value="1" checked>/', $noDate->body);
    }

    public function testUrgencyIsAndedWithOtherFilters(): void
    {
        $this->populate();
        self::assertEqualsCanonicalizing(['C-jour', 'M-j6'], self::titles($this->get('/tasks', ['project' => 'OPF', 'urgency' => 'week'])));
        self::assertEqualsCanonicalizing(['C-jour'], self::titles($this->get('/tasks', ['project' => 'OPF', 'urgency' => 'week', 'importance' => 'Critique', 'hide_done' => '1'])));
        self::assertSame(['C-retard'], self::titles($this->get('/tasks', ['project' => 'OPF', 'urgency' => 'late'])));
        $empty = $this->get('/tasks', ['project' => 'OPF', 'urgency' => 'week', 'status' => 'done']);
        self::assertSame(200, $empty->status);
        self::assertSame([], self::titles($empty), 'week + Terminée = vide');
        self::assertStringContainsString('Aucune tâche ne correspond aux filtres.', $empty->body);
        self::assertSame([], self::titles($this->get('/tasks', ['urgency' => 'week', 'no_due_date' => '1'])), 'échéance + sans date = vide');
        self::assertCount(16, self::titles($this->get('/tasks', ['urgency' => ''])), 'urgency vide = filtre inactif : toutes les tâches');
    }

    public function testNoDueDateOtherThanOneIsIgnored(): void
    {
        $this->populate();
        $all = count(self::titles($this->get('/tasks')));
        self::assertSame($all, count(self::titles($this->get('/tasks', ['no_due_date' => '0']))));
        self::assertSame($all, count(self::titles($this->get('/tasks', ['no_due_date' => 'oui']))));
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidUrgency(): array
    {
        return [
            'inconnue' => [['urgency' => 'bogus']],
            'casse' => [['urgency' => 'WEEK']],
            'tableau' => [['urgency' => ['x']]],
            'avec projet' => [['urgency' => 'bogus', 'project' => 'OPF']],
        ];
    }

    #[DataProvider('invalidUrgency')]
    public function testInvalidUrgencyIsReadableErrorWithoutList(array $query): void
    {
        $this->populate();
        $r = $this->get('/tasks', $query);
        self::assertSame(422, $r->status);
        self::assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
        self::assertStringContainsString('Filtre invalide', $r->body);
        self::assertStringContainsString('Échéance inconnue. Choisissez en retard, moins d\'une semaine, moins d\'un mois ou au-delà.', html_entity_decode($r->body, ENT_QUOTES, 'UTF-8'));
        self::assertStringNotContainsString('<table', $r->body);
        self::assertStringNotContainsString('C-retard', $r->body);
        self::assertStringNotContainsString('urgency_invalid', $r->body, 'aucun code technique');
    }

    public function testReferenceDayIsInjectedAndSharedByViewAndList(): void
    {
        $this->populate();
        $later = Bootstrap::kernel($this->config, $this->session, static fn (): string => '2026-10-26');
        $page = $later->handle(new Request('GET', '/projects/' . $this->projectId('OPF'), ['host' => self::HOST]))->body;
        foreach (self::cells($page) as [$count, $href]) {
            if ($href === null) {
                continue;
            }
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $list = $later->handle(new Request('GET', '/tasks', ['host' => self::HOST], '', $query));
            self::assertCount($count, self::titles($list), $href);
        }
        // Au 26/10 : C-retard, C-jour et C-mois sont en retard; M-j6 et m-j7 aussi.
        $cells = self::cells($page);
        self::assertSame(3, $cells[0][0]);
        self::assertSame(1, $cells[5][0]);
        self::assertSame(1, $cells[8][0]);
    }

    // ----- Liste : version mobile (structure HTML) -----

    public function testListKeepsSevenColumnsWithSecondaryOnesMarkedAndFiltersInDetails(): void
    {
        $this->populate();
        $html = $this->get('/tasks', ['project' => 'OPF'])->body;
        self::assertSame(1, preg_match_all('#<th scope="col">Titre</th>#', $html));
        self::assertSame(6, preg_match_all('#<th scope="col" class="col-extra">#', $html), 'six en-têtes secondaires');
        self::assertSame(6 * 14, preg_match_all('#<td class="col-extra">#', $html), 'six cellules secondaires par ligne (14 lignes)');
        self::assertSame(14, preg_match_all('#<td class="title">#', $html));
        // Les valeurs masquées à l'écran étroit sont dans le HTML.
        foreach (['Pro', 'OPF', 'Critique', 'À faire', '17/10/2026'] as $value) {
            self::assertStringContainsString('>' . $value, $html);
        }
        // Filtres repliés derrière <details>/<summary>, formulaire GET à l'intérieur.
        self::assertMatchesRegularExpression('#<details class="filters-panel">\s*<summary>Filtres</summary>\s*<form method="get" action="/tasks" class="filters"#', $html);
        self::assertMatchesRegularExpression('#</form>\s*</details>#', $html);
        self::assertStringNotContainsString('<details class="filters-panel" open', $html, 'replié par défaut');
        $form = substr($html, (int) strpos($html, '<form'), (int) strpos($html, '</form>') - (int) strpos($html, '<form'));
        foreach (['f-status', 'f-category', 'f-project', 'f-importance', 'f-urgency', 'f-no-due-date', 'f-hide-done'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $form, $id);
        }
        self::assertStringContainsString('<label for="f-urgency">Échéance</label>', $form);
        self::assertStringContainsString('Ne concerne que les tâches non terminées', $form);
    }

    public function testEmptyListKeepsTheSameMessage(): void
    {
        self::assertStringContainsString('Aucune tâche.', $this->get('/tasks')->body);
    }

    // ----- Non-régression REST / MCP (E1 : portail seul) -----

    public function testRestAndMcpDoNotGainTheUrgencyFilters(): void
    {
        $this->populate();
        $rest = $this->kernel->handle(new Request('GET', '/api/v1/tasks', [], '', ['urgency' => 'week']));
        self::assertSame(200, $rest->status, 'REST : urgency ignoré');
        $all = $this->kernel->handle(new Request('GET', '/api/v1/tasks'));
        self::assertSame($all->body, $rest->body, 'REST : liste complète, non filtrée');
        $noDate = $this->kernel->handle(new Request('GET', '/api/v1/tasks', [], '', ['no_due_date' => '1']));
        self::assertSame($all->body, $noDate->body);

        foreach (['urgency' => 'week', 'no_due_date' => '1'] as $key => $value) {
            $body = json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'list_tasks', 'arguments' => [$key => $value]]], JSON_THROW_ON_ERROR);
            $r = $this->kernel->handle(new Request('POST', '/mcp', ['host' => self::HOST, 'content-type' => 'application/json'], $body));
            $rpc = json_decode($r->body, true, 64, JSON_THROW_ON_ERROR);
            self::assertTrue($rpc['result']['isError'], "MCP : $key refusé");
            self::assertSame('field_unknown', json_decode($rpc['result']['content'][0]['text'], true)['error']['code']);
        }
    }
}
