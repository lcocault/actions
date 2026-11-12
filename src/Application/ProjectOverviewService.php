<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Synthèse par état et matrice d'Eisenhower (importance x urgence), calculées sur une seule lecture des tâches
 * d'un projet ou de tous les projets (cellules + hors grille = total, par construction).
 *
 * `overview()` renvoie `ProjectOverview`; `globalOverview()` renvoie les mêmes champs sans `project`.
 * Forme (`ProjectOverview`) : project (Project), today (AAAA-MM-JJ), summary {todo,in_progress,done,total},
 * matrix {columns, rows[{importance, cells{late,week,month,later}}]}, outside {done:{count}, no_due_date:{count,tasks}}.
 * MatrixCell = {count, estimated_minutes, tasks[{id,title,status,due_date,late,estimated_minutes}]}.
 */
final class ProjectOverviewService
{
    /** @var \Closure():string */
    private readonly \Closure $today;

    /** @param ?\Closure():string $today jour de référence AAAA-MM-JJ; défaut : jour courant en Europe/Paris */
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        ?\Closure $today = null,
    ) {
        $this->today = $today ?? self::parisToday();
    }

    /** Jour de référence par défaut : jour courant en Europe/Paris (partagé avec le filtre d'urgence du portail). */
    public static function parisToday(): \Closure
    {
        return static fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

    /**
     * @return array<string,mixed> ProjectOverview
     * @throws ProjectNotFound
     */
    public function overview(int $id): array
    {
        $project = $this->projects->find($id) ?? throw new ProjectNotFound($id);
        $today = ($this->today)();
        $overview = $this->summarize($this->tasks->findOverviewRows($id), $today);

        return ['project' => $project, 'today' => $today] + $overview;
    }

    /**
     * Matrice de toutes les tâches, sans synthèse de projet.
     *
     * @return array<string,mixed>
     */
    public function globalOverview(): array
    {
        $today = ($this->today)();

        return ['today' => $today] + $this->summarize($this->tasks->findOverviewRows(null), $today);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function summarize(array $rows, string $today): array
    {
        $summary = ['todo' => 0, 'in_progress' => 0, 'done' => 0, 'total' => 0];
        $cells = [];
        foreach (TaskValidator::IMPORTANCES as $importance) {
            foreach (UrgencyClassifier::COLUMNS as $column) {
                $cells[$importance][$column] = ['count' => 0, 'estimated_minutes' => null, 'tasks' => []];
            }
        }
        $doneCount = 0;
        $noDueDate = ['count' => 0, 'tasks' => []];

        foreach ($rows as $row) {
            ++$summary['total'];
            ++$summary[$row['status']];
            $place = UrgencyClassifier::placement($row['status'], $row['due_date'], $today);
            if ($place['zone'] === UrgencyClassifier::ZONE_DONE) {
                ++$doneCount;
                continue;
            }
            if ($place['zone'] === UrgencyClassifier::ZONE_NO_DUE_DATE) {
                $noDueDate['tasks'][] = ['id' => $row['id'], 'title' => $row['title'], 'status' => $row['status'], 'due_date' => null, 'late' => false];
                ++$noDueDate['count'];
                continue;
            }
            $cell = &$cells[$row['importance']][(string) $place['column']];
            $cell['tasks'][] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'status' => $row['status'],
                'due_date' => $row['due_date'],
                'late' => $place['late'],
                'estimated_minutes' => $row['estimated_minutes'],
            ];
            if ($row['estimated_minutes'] !== null) {
                $cell['estimated_minutes'] = ($cell['estimated_minutes'] ?? 0) + $row['estimated_minutes'];
            }
            ++$cell['count'];
            unset($cell);
        }

        $matrixRows = [];
        foreach (array_reverse(TaskValidator::IMPORTANCES) as $importance) { // Critique, Majeure, Mineure
            $matrixRows[] = ['importance' => $importance, 'cells' => $cells[$importance]];
        }

        return [
            'summary' => $summary,
            'matrix' => ['columns' => UrgencyClassifier::COLUMNS, 'rows' => $matrixRows],
            'outside' => ['done' => ['count' => $doneCount], 'no_due_date' => $noDueDate],
        ];
    }
}
