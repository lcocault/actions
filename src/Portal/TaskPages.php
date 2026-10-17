<?php

declare(strict_types=1);

namespace Actions\Portal;

use Actions\Application\DomainError;
use Actions\Application\InvalidTask;
use Actions\Application\ProjectService;
use Actions\Application\TaskIsRequired;
use Actions\Application\TaskNotFound;
use Actions\Application\TaskService;
use Actions\Application\TaskStatus;
use Actions\Application\TaskValidator;
use Actions\Http\DomainStatus;
use Actions\Http\PathId;
use Actions\Http\Request;
use Actions\Http\Response;

/**
 * Pages de tâches (B1) : un cas d'usage de TaskService par méthode. Traduction formulaire -> cas d'usage
 * -> page; aucune règle métier. Les exceptions non traitées ici (TaskNotFound, base indisponible...)
 * remontent à PortalAdapter, qui les convertit en page d'erreur.
 */
final class TaskPages
{
    /** Champ de la validation -> champ du formulaire. */
    private const FORM_FIELD = [
        'title' => 'title',
        'due_date' => 'due_date',
        'estimated_minutes' => 'estimated_minutes',
        'description' => 'description',
        'project' => 'project',
        'conclusions' => 'conclusions',
        'importance' => 'importance',
        'recurrence' => 'recurrence',
        'recurrence.interval' => 'recurrence',
        'recurrence.unit' => 'recurrence',
    ];
    /** Champ du formulaire -> identifiant du contrôle à atteindre depuis le résumé d'erreurs. */
    private const ANCHOR = [
        'title' => 'f-title',
        'due_date' => 'f-due_date',
        'estimated_minutes' => 'f-estimated_minutes',
        'description' => 'f-description',
        'project' => 'f-project',
        'conclusions' => 'f-conclusions',
        'importance' => 'f-importance',
        'recurrence' => 'f-recurrence_interval',
    ];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ProjectService $projects,
        private readonly Renderer $renderer,
    ) {
    }

    public function list(Request $request): Response
    {
        $filters = ['status' => '', 'category' => '', 'project' => '', 'importance' => '', 'hide_done' => ($request->query['hide_done'] ?? '') === '1'];
        $criteria = [];
        foreach (['status', 'category', 'project', 'importance'] as $name) {
            $value = $request->query[$name] ?? '';
            if ($value === '') { // « Tous » : filtre absent (REST, lui, refuse la valeur vide)
                continue;
            }
            $criteria[$name] = $value;
            $filters[$name] = is_string($value) ? $value : '';
        }
        try {
            $tasks = $this->tasks->list($criteria);
        } catch (InvalidTask $e) {
            return $this->renderer->error(DomainStatus::of($e) ?? 422, 'Filtre invalide', Messages::for($e));
        }
        if ($filters['hide_done']) { // présentation seulement : aucune règle métier, TaskService inchangé
            $tasks = array_values(array_filter($tasks, static fn (array $t): bool => $t['status'] !== 'done'));
        }

        return $this->renderer->page(200, 'Tâches', 'task_list', [
            'tasks' => $tasks,
            'filters' => $filters,
            'statuses' => Labels::statuses(),
            'isFiltered' => $filters['status'] !== '' || $filters['category'] !== '' || $filters['project'] !== '' || $filters['importance'] !== '' || $filters['hide_done'],
            'projects' => $this->projects->names(),
            'importances' => TaskValidator::IMPORTANCES,
        ]);
    }

    public function show(int $id): Response
    {
        return $this->detail($this->tasks->get($id));
    }

    /**
     * Page de détail; en cas de refus d'une action, rendue avec le statut de l'erreur (la tâche est inchangée).
     *
     * @param array<string,mixed> $task
     * @param ?array{message:string,items:list<array{id:int,title:string,status:string}>} $stateError
     */
    private function detail(array $task, int $status = 200, ?array $stateError = null, ?string $requiredError = null, string $requiredInput = ''): Response
    {
        // Task.required_actions ne porte que {id, title} : l'état des actions requises et la liste des
        // candidats viennent d'un seul list().
        $all = $this->tasks->list();
        $statusById = [];
        foreach ($all as $other) {
            $statusById[$other['id']] = $other['status'];
        }
        $transitions = [];
        foreach (TaskStatus::from($task['status'])->allowedTargets() as $target) {
            $transitions[] = [
                'target' => $target->value,
                'label' => Labels::transition($task['status'], $target->value),
                'reopenPage' => $task['status'] === 'done' && $task['recurrence'] !== null, // PW9 : avertissement
            ];
        }

        return $this->renderer->page($status, $task['title'], 'task_detail', [
            'task' => $task,
            'statusById' => $statusById,
            'candidates' => $all,
            'transitions' => $transitions,
            'stateError' => $stateError,
            'requiredError' => $requiredError,
            'requiredInput' => $requiredInput,
            'csrf' => $this->renderer->csrf(),
        ]);
    }

    public function addRequiredAction(int $id, Request $request): Response
    {
        $task = $this->tasks->get($id);
        $raw = $request->form['required_id'] ?? '';
        $requiredId = is_string($raw) ? PathId::parse($raw) : null;
        if ($requiredId === null) { // erreur de forme du formulaire, pas une règle métier
            return $this->detail($task, 422, null, 'Choisissez une tâche.', is_string($raw) ? $raw : '');
        }
        // Information de présentation seulement : l'ajout d'un lien existant est un succès idempotent.
        $already = in_array($requiredId, array_column($task['required_actions'], 'id'), true);
        try {
            $this->tasks->addRequiredAction($id, $requiredId);
        } catch (TaskNotFound $e) {
            throw $e; // supprimée entre-temps : page 404
        } catch (DomainError $e) {
            return $this->detail($task, DomainStatus::of($e) ?? 500, null, Messages::for($e), (string) $requiredId);
        }
        $this->renderer->flash(['type' => 'success', 'text' => $already ? 'Cette action requise était déjà présente.' : 'Action requise ajoutée.']);

        return $this->renderer->redirect('/tasks/' . $id);
    }

    public function removeRequiredAction(int $id, int $requiredId): Response
    {
        $this->tasks->removeRequiredAction($id, $requiredId);
        $this->renderer->flash(['type' => 'success', 'text' => 'Action requise retirée.']);

        return $this->renderer->redirect('/tasks/' . $id);
    }

    public function changeStatus(int $id, Request $request): Response
    {
        $task = $this->tasks->get($id);
        try {
            $result = $this->tasks->changeStatus($id, $request->form['status'] ?? null);
        } catch (TaskNotFound $e) {
            throw $e;
        } catch (DomainError $e) {
            $items = [];
            if ($e->code() === 'required_actions_not_done') {
                /** @var list<array{id:int,title:string,status:string}> $items */
                $items = $e->details()['required_actions'] ?? [];
            }

            return $this->detail($task, DomainStatus::of($e) ?? 500, ['message' => Messages::for($e), 'items' => $items]);
        }
        $flash = ['type' => 'success', 'text' => match ([$task['status'], $result['task']['status']]) {
            ['todo', 'in_progress'] => 'Tâche démarrée.',
            ['in_progress', 'done'] => 'Tâche terminée.',
            ['done', 'todo'] => 'Tâche rouverte.',
            default => 'Tâche remise à faire.',
        }];
        $next = $result['next_occurrence'];
        if ($next !== null) {
            $flash['text'] .= ' Prochaine occurrence : « ' . $next['title'] . ' », échéance ' . Labels::date($next['due_date']) . '.';
            $flash['link'] = ['label' => 'Voir la prochaine occurrence', 'path' => '/tasks/' . $next['id']];
        }
        $this->renderer->flash($flash);

        return $this->renderer->redirect('/tasks/' . $id);
    }

    public function reopenConfirm(int $id): Response
    {
        $task = $this->tasks->get($id);
        if ($task['status'] !== 'done' || $task['recurrence'] === null) {
            return $this->renderer->redirect('/tasks/' . $id);
        }

        return $this->renderer->page(200, 'Rouvrir la tâche', 'task_reopen', ['task' => $task, 'csrf' => $this->renderer->csrf()]);
    }

    public function newForm(): Response
    {
        return $this->form(200, 'Nouvelle tâche', '/tasks', 'Créer la tâche', '/tasks', TaskForm::emptyValues());
    }

    public function create(Request $request): Response
    {
        try {
            $task = $this->tasks->create(TaskForm::toInput($request->form));
        } catch (InvalidTask $e) {
            return $this->form(
                DomainStatus::of($e) ?? 422,
                'Nouvelle tâche',
                '/tasks',
                'Créer la tâche',
                '/tasks',
                TaskForm::valuesFromForm($request->form),
                $e,
            );
        }
        $this->renderer->flash(['type' => 'success', 'text' => 'Tâche créée.']);

        return $this->renderer->redirect('/tasks/' . $task['id']);
    }

    public function editForm(int $id): Response
    {
        $task = $this->tasks->get($id);

        return $this->form(
            200,
            'Modifier la tâche',
            '/tasks/' . $id,
            'Enregistrer',
            '/tasks/' . $id,
            TaskForm::valuesFromTask($task),
        );
    }

    public function update(int $id, Request $request): Response
    {
        try {
            $this->tasks->update($id, TaskForm::toInput($request->form));
        } catch (InvalidTask $e) {
            return $this->form(
                DomainStatus::of($e) ?? 422,
                'Modifier la tâche',
                '/tasks/' . $id,
                'Enregistrer',
                '/tasks/' . $id,
                TaskForm::valuesFromForm($request->form),
                $e,
            );
        }
        $this->renderer->flash(['type' => 'success', 'text' => 'Tâche enregistrée.']);

        return $this->renderer->redirect('/tasks/' . $id);
    }

    public function deleteConfirm(int $id): Response
    {
        return $this->confirmation(200, $this->tasks->get($id), []);
    }

    public function delete(int $id): Response
    {
        $task = $this->tasks->get($id); // 404 si inconnue; le titre sert au message
        try {
            $this->tasks->delete($id);
        } catch (TaskIsRequired $e) {
            /** @var list<array{id:int,title:string}> $dependents */
            $dependents = $e->details()['dependents'] ?? [];

            return $this->confirmation(DomainStatus::of($e) ?? 409, $task, $dependents);
        }
        $this->renderer->flash(['type' => 'success', 'text' => 'Tâche « ' . $task['title'] . ' » supprimée.']);

        return $this->renderer->redirect('/tasks');
    }

    /**
     * @param array<string,mixed> $task
     * @param list<array{id:int,title:string}> $dependents
     */
    private function confirmation(int $status, array $task, array $dependents): Response
    {
        return $this->renderer->page($status, 'Supprimer la tâche', 'task_delete', [
            'task' => $task,
            'dependents' => $dependents,
            'csrf' => $this->renderer->csrf(),
        ]);
    }

    /** @param array<string,string> $values */
    private function form(int $status, string $heading, string $action, string $submit, string $cancel, array $values, ?InvalidTask $error = null): Response
    {
        $summary = [];
        $errors = [];
        if ($error !== null) {
            $message = Messages::for($error);
            $field = self::FORM_FIELD[(string) ($error->details()['field'] ?? '')] ?? null;
            $summary[] = ['message' => $message, 'anchor' => $field === null ? null : self::ANCHOR[$field]];
            if ($field !== null) {
                $errors[$field][] = $message;
            }
            if ($error->code() === 'recurrence_requires_due_date') { // sous la date butée et sous la périodicité
                $errors['due_date'][] = $message;
                $summary[0]['anchor'] = self::ANCHOR['due_date'];
            }
        }

        return $this->renderer->page($status, $heading, 'task_form', [
            'heading' => $heading,
            'action' => $action,
            'submit' => $submit,
            'cancel' => $cancel,
            'values' => $values,
            'summary' => $summary,
            'errors' => $errors,
            'units' => Labels::formUnits(),
            'projects' => $this->projects->names(),
            'importances' => TaskValidator::IMPORTANCES,
            'csrf' => $this->renderer->csrf(),
        ]);
    }
}
