<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\ProjectNotFound;
use Actions\Application\ProjectOverviewService;
use Actions\Application\ProjectService;
use Actions\Application\TaskService;
use Actions\Bootstrap;

/** Synthèse et matrice d'un projet contre PostgreSQL réel, date du jour injectée (2026-10-18). */
final class ProjectOverviewServiceTest extends DbTestCase
{
    private const TODAY = '2026-10-18';

    private ProjectOverviewService $overview;
    private TaskService $tasks;
    private ProjectService $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->overview = Bootstrap::projectOverviewService($this->config, static fn (): string => self::TODAY);
        $this->tasks = Bootstrap::taskService($this->config);
        $this->projects = Bootstrap::projectService($this->config);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function task(string $title, string $importance, ?string $due, string $status = 'todo', string $project = 'OPF', array $extra = []): array
    {
        $task = $this->tasks->create(['title' => $title, 'project' => $project, 'importance' => $importance, 'due_date' => $due] + $extra);
        if ($status === 'in_progress') {
            $this->tasks->changeStatus($task['id'], 'in_progress');
        } elseif ($status === 'done') {
            $this->tasks->changeStatus($task['id'], 'in_progress');
            $this->tasks->changeStatus($task['id'], 'done');
        }

        return $task;
    }

    /** @return array<string,mixed> */
    private function ov(string $project = 'OPF'): array
    {
        return $this->overview->overview($this->projectId($project));
    }

    /** @param list<string> $expected titres dans l'ordre */
    private function assertCell(array $ov, string $importance, string $column, array $expected): void
    {
        foreach ($ov['matrix']['rows'] as $row) {
            if ($row['importance'] === $importance) {
                $cell = $row['cells'][$column];
                self::assertSame($expected, array_column($cell['tasks'], 'title'), "$importance/$column");
                self::assertSame(count($expected), $cell['count'], "$importance/$column");

                return;
            }
        }
        self::fail("ligne $importance absente");
    }

    /** Invariants : cellules + hors grille = total = task_count; états = total. */
    private function assertInvariants(array $ov): void
    {
        $s = $ov['summary'];
        self::assertSame($s['total'], $s['todo'] + $s['in_progress'] + $s['done']);
        $cells = 0;
        foreach ($ov['matrix']['rows'] as $row) {
            self::assertSame(['late', 'week', 'month', 'later'], array_keys($row['cells']));
            foreach ($row['cells'] as $column => $cell) {
                self::assertSame($cell['count'], count($cell['tasks']));
                $cells += $cell['count'];
                foreach ($cell['tasks'] as $t) {
                    self::assertSame($t['late'], $column === 'late');
                }
            }
        }
        $out = $ov['outside'];
        self::assertSame($out['no_due_date']['count'], count($out['no_due_date']['tasks']));
        self::assertSame($s['total'], $cells + $out['done']['count'] + $out['no_due_date']['count']);
        self::assertSame($s['done'], $out['done']['count']);
        self::assertSame($ov['project']['task_count'], $s['total']);
    }

    public function testEmptyProjectGivesZerosEverywhere(): void
    {
        $ov = $this->ov('Erendil');
        self::assertSame(['todo' => 0, 'in_progress' => 0, 'done' => 0, 'total' => 0], $ov['summary']);
        self::assertSame(self::TODAY, $ov['today']);
        self::assertSame(['late', 'week', 'month', 'later'], $ov['matrix']['columns']);
        self::assertSame(['Critique', 'Majeure', 'Mineure'], array_column($ov['matrix']['rows'], 'importance'));
        self::assertSame(0, $ov['outside']['done']['count']);
        self::assertSame(['count' => 0, 'tasks' => []], $ov['outside']['no_due_date']);
        self::assertSame('Erendil', $ov['project']['name']);
        $this->assertInvariants($ov);
    }

    public function testEveryCellOutsideAndSummary(): void
    {
        $this->task('c-retard', 'Critique', '2026-10-10');
        $this->task('c-semaine', 'Critique', '2026-10-24', 'in_progress');
        $this->task('c-mois', 'Critique', '2026-11-17');
        $this->task('c-plus', 'Critique', '2026-11-18');
        $this->task('M-semaine', 'Majeure', '2026-10-18');
        $this->task('M-mois', 'Majeure', '2026-10-25');
        $this->task('M-plus', 'Majeure', '2030-01-01');
        $this->task('m-semaine', 'Mineure', '2026-10-19');
        $this->task('m-mois', 'Mineure', '2026-10-26');
        $this->task('m-plus', 'Mineure', '2026-12-01');
        $this->task('sans-date', 'Majeure', null);
        $this->task('sans-date-en-cours', 'Critique', null, 'in_progress');
        $this->task('finie', 'Critique', '2026-10-01', 'done');
        $this->task('finie-sans-date', 'Mineure', null, 'done');
        $this->task('ailleurs', 'Critique', '2026-10-18', 'todo', 'Jardin/Maison');

        $ov = $this->ov();
        $this->assertInvariants($ov);
        self::assertSame(['todo' => 10, 'in_progress' => 2, 'done' => 2, 'total' => 14], $ov['summary']);
        self::assertSame(14, $ov['project']['task_count']);

        $this->assertCell($ov, 'Critique', 'late', ['c-retard']);
        $this->assertCell($ov, 'Critique', 'week', ['c-semaine']);
        $this->assertCell($ov, 'Critique', 'month', ['c-mois']);
        $this->assertCell($ov, 'Critique', 'later', ['c-plus']);
        $this->assertCell($ov, 'Majeure', 'week', ['M-semaine']);
        $this->assertCell($ov, 'Majeure', 'month', ['M-mois']);
        $this->assertCell($ov, 'Majeure', 'later', ['M-plus']);
        $this->assertCell($ov, 'Mineure', 'week', ['m-semaine']);
        $this->assertCell($ov, 'Mineure', 'month', ['m-mois']);
        $this->assertCell($ov, 'Mineure', 'later', ['m-plus']);

        $first = $ov['matrix']['rows'][0]['cells']['late']['tasks'];
        self::assertTrue($first[0]['late']);
        self::assertSame('2026-10-10', $first[0]['due_date']);
        $week = $ov['matrix']['rows'][0]['cells']['week']['tasks'];
        self::assertFalse($week[0]['late']);
        self::assertSame('in_progress', $week[0]['status']);

        self::assertSame(2, $ov['outside']['done']['count']);
        self::assertSame(['sans-date', 'sans-date-en-cours'], array_column($ov['outside']['no_due_date']['tasks'], 'title'));
        self::assertSame(['id', 'title', 'status', 'due_date', 'late'], array_keys($ov['outside']['no_due_date']['tasks'][0]));
        self::assertNull($ov['outside']['no_due_date']['tasks'][0]['due_date']);
        self::assertArrayNotHasKey('tasks', $ov['outside']['done']);

        // L'autre projet est compté de son côté seulement.
        $other = $this->ov('Jardin/Maison');
        self::assertSame(1, $other['summary']['total']);
        $this->assertInvariants($other);
    }

    public function testTasksOfACellAreOrderedByDueDateThenId(): void
    {
        $this->task('b', 'Majeure', '2026-10-20');
        $this->task('a', 'Majeure', '2026-10-19');
        $this->task('c', 'Majeure', '2026-10-20');
        $this->assertCell($this->ov(), 'Majeure', 'week', ['a', 'b', 'c']);
    }

    public function testChangesMoveTheTaskOnNextCall(): void
    {
        $t = $this->task('mobile', 'Mineure', '2026-12-25');
        $this->assertCell($this->ov(), 'Mineure', 'later', ['mobile']);

        $this->tasks->update($t['id'], ['due_date' => '2026-10-20']);
        $this->assertCell($this->ov(), 'Mineure', 'week', ['mobile']);

        $this->tasks->update($t['id'], ['importance' => 'Critique']);
        $ov = $this->ov();
        $this->assertCell($ov, 'Critique', 'week', ['mobile']);
        $this->assertCell($ov, 'Mineure', 'week', []);

        $this->tasks->changeStatus($t['id'], 'in_progress');
        $this->assertCell($this->ov(), 'Critique', 'week', ['mobile']);
        self::assertSame(1, $this->ov()['summary']['in_progress']);

        $this->tasks->changeStatus($t['id'], 'done');
        $ov = $this->ov();
        $this->assertCell($ov, 'Critique', 'week', []);
        self::assertSame(1, $ov['outside']['done']['count']);
        self::assertSame(1, $ov['summary']['done']);
        $this->assertInvariants($ov);

        $this->tasks->update($t['id'], ['due_date' => null]);
        $this->assertInvariants($this->ov());
    }

    public function testRecurringTaskCountsWithItsCurrentDueDate(): void
    {
        $t = $this->task('périodique', 'Majeure', '2026-10-20', 'todo', 'OPF', ['recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $this->assertCell($this->ov(), 'Majeure', 'week', ['périodique']);

        $this->tasks->changeStatus($t['id'], 'in_progress');
        $this->tasks->changeStatus($t['id'], 'done'); // crée l'occurrence suivante au 2026-11-20
        $ov = $this->ov();
        $this->assertInvariants($ov);
        self::assertSame(2, $ov['summary']['total']);
        self::assertSame(1, $ov['outside']['done']['count']);
        $this->assertCell($ov, 'Majeure', 'week', []);
        $this->assertCell($ov, 'Majeure', 'later', ['périodique']);
    }

    public function testRenamingTheProjectKeepsTheSameResult(): void
    {
        $this->task('x', 'Critique', '2026-10-19');
        $id = $this->projectId('OPF');
        $before = $this->overview->overview($id);
        $this->projects->update($id, ['name' => 'Renommé']);
        $after = $this->overview->overview($id);
        self::assertSame('Renommé', $after['project']['name']);
        self::assertSame($before['matrix'], $after['matrix']);
        self::assertSame($before['summary'], $after['summary']);
    }

    public function testUnknownProject(): void
    {
        $this->expectException(ProjectNotFound::class);
        $this->overview->overview(999999);
    }

    public function testDefaultClockReturnsAValidDay(): void
    {
        $service = Bootstrap::projectOverviewService($this->config);
        $today = $service->overview($this->projectId('OPF'))['today'];
        self::assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $today));
        self::assertTrue(checkdate((int) substr($today, 5, 2), (int) substr($today, 8, 2), (int) substr($today, 0, 4)));
    }
}
