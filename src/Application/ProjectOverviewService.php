<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Vue d'un projet : synthèse par état et matrice d'Eisenhower (importance x urgence), calculées sur une seule
 * lecture des tâches du projet (cellules + hors grille = total, par construction).
 *
 * Forme (`ProjectOverview`) : project (Project), today (AAAA-MM-JJ), summary {todo,in_progress,done,total},
 * matrix {columns, rows[{importance, cells{late,week,month,later}}]}, outside {done:{count}, no_due_date:Cell}.
 * Cell = {count, tasks[{id,title,status,due_date,late}]}.
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
        $rows = $this->tasks->findOverviewRows($id);

        $summary = ['todo' => 0, 'in_progress' => 0, 'done' => 0, 'total' => 0];
        $cells = [];
        foreach (TaskValidator::IMPORTANCES as $importance) {
            foreach (UrgencyClassifier::COLUMNS as $column) {
                $cells[$importance][$column] = ['count' => 0, 'tasks' => []];
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
            $cell['tasks'][] = ['id' => $row['id'], 'title' => $row['title'], 'status' => $row['status'], 'due_date' => $row['due_date'], 'late' => $place['late']];
            ++$cell['count'];
            unset($cell);
        }

        $matrixRows = [];
        foreach (array_reverse(TaskValidator::IMPORTANCES) as $importance) { // Critique, Majeure, Mineure
            $matrixRows[] = ['importance' => $importance, 'cells' => $cells[$importance]];
        }

        return [
            'project' => $project,
            'today' => $today,
            'summary' => $summary,
            'matrix' => ['columns' => UrgencyClassifier::COLUMNS, 'rows' => $matrixRows],
            'outside' => ['done' => ['count' => $doneCount], 'no_due_date' => $noDueDate],
        ];
    }
}
