<?php

declare(strict_types=1);

namespace Actions\Portal;

use Actions\Application\InvalidProject;
use Actions\Application\ProjectInUse;
use Actions\Application\ProjectNameTaken;
use Actions\Application\ProjectOverviewService;
use Actions\Application\ProjectService;
use Actions\Application\TaskValidator;
use Actions\Http\DomainStatus;
use Actions\Http\Request;
use Actions\Http\Response;

/**
 * Pages de projets : un cas d'usage de ProjectService (ou ProjectOverviewService) par méthode. Traduction
 * formulaire -> cas d'usage -> page; aucune règle métier, aucun comptage ni classement. ProjectNotFound et
 * la base indisponible remontent à PortalAdapter.
 */
final class ProjectPages
{
    private const ANCHOR = ['name' => 'f-name', 'category' => 'f-category'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectOverviewService $overview,
        private readonly Renderer $renderer,
    ) {
    }

    public function list(): Response
    {
        return $this->renderer->page(200, 'Projets', 'project_list', ['projects' => $this->projects->list()]);
    }

    public function show(int $id): Response
    {
        $overview = $this->overview->overview($id);

        return $this->renderer->page(200, 'Projet ' . $overview['project']['name'], 'project_detail', ['overview' => $overview]);
    }

    public function newForm(): Response
    {
        return $this->form(200, 'Nouveau projet', '/projects', 'Créer le projet', '/projects', ['name' => '', 'category' => ''], null, null);
    }

    public function create(Request $request): Response
    {
        $input = self::input($request);
        try {
            $project = $this->projects->create($input);
        } catch (InvalidProject | ProjectNameTaken $e) {
            return $this->form(DomainStatus::of($e) ?? 422, 'Nouveau projet', '/projects', 'Créer le projet', '/projects', self::values($request), null, $e);
        }
        $this->renderer->flash(['type' => 'success', 'text' => 'Projet « ' . $project['name'] . ' » créé.']);

        return $this->renderer->redirect('/projects');
    }

    public function editForm(int $id): Response
    {
        $project = $this->projects->get($id);

        return $this->form(200, 'Modifier le projet', '/projects/' . $id, 'Enregistrer', '/projects', ['name' => $project['name'], 'category' => $project['category']], $project['task_count'], null);
    }

    public function update(int $id, Request $request): Response
    {
        $project = $this->projects->get($id); // 404 si inconnu; le nombre de tâches sert à l'avertissement du formulaire
        try {
            $result = $this->projects->update($id, self::input($request));
        } catch (InvalidProject | ProjectNameTaken $e) {
            return $this->form(DomainStatus::of($e) ?? 422, 'Modifier le projet', '/projects/' . $id, 'Enregistrer', '/projects', self::values($request), $project['task_count'], $e);
        }
        $text = 'Projet enregistré.';
        if ($result['tasks_recategorized'] > 0) {
            $text .= ' Catégorie changée : ' . $result['tasks_recategorized'] . ' tâche(s) concernée(s).';
        }
        $this->renderer->flash(['type' => 'success', 'text' => $text]);

        return $this->renderer->redirect('/projects');
    }

    public function deleteConfirm(int $id): Response
    {
        return $this->confirmation(200, $this->projects->get($id), null);
    }

    public function delete(int $id): Response
    {
        $project = $this->projects->get($id); // 404 si inconnu; le nom sert au message
        try {
            $this->projects->delete($id);
        } catch (ProjectInUse $e) {
            return $this->confirmation(DomainStatus::of($e) ?? 409, $project, (int) ($e->details()['task_count'] ?? $project['task_count']));
        }
        $this->renderer->flash(['type' => 'success', 'text' => 'Projet « ' . $project['name'] . ' » supprimé.']);

        return $this->renderer->redirect('/projects');
    }

    /**
     * Transport seul : nom et catégorie tels que saisis (valeur absente = chaîne vide, refusée par le domaine).
     *
     * @return array{name:mixed,category:mixed}
     */
    private static function input(Request $request): array
    {
        return ['name' => $request->form['name'] ?? '', 'category' => $request->form['category'] ?? ''];
    }

    /** @return array{name:string,category:string} saisie à réafficher (valeur non textuelle -> vide) */
    private static function values(Request $request): array
    {
        $name = $request->form['name'] ?? '';
        $category = $request->form['category'] ?? '';

        return ['name' => is_string($name) ? $name : '', 'category' => is_string($category) ? $category : ''];
    }

    /**
     * @param array<string,mixed> $project
     * @param ?int $blockedBy nombre de tâches qui bloquent la suppression (après un refus), sinon null
     */
    private function confirmation(int $status, array $project, ?int $blockedBy): Response
    {
        return $this->renderer->page($status, 'Supprimer le projet', 'project_delete', [
            'project' => $project,
            'blockedBy' => $blockedBy,
            'csrf' => $this->renderer->csrf(),
        ]);
    }

    /** @param array{name:string,category:string} $values */
    private function form(int $status, string $heading, string $action, string $submit, string $cancel, array $values, ?int $taskCount, InvalidProject|ProjectNameTaken|null $error): Response
    {
        $summary = [];
        $errors = [];
        if ($error !== null) {
            $message = Messages::for($error);
            $field = (string) ($error->details()['field'] ?? '');
            $summary[] = ['message' => $message, 'anchor' => self::ANCHOR[$field] ?? null];
            if (isset(self::ANCHOR[$field])) {
                $errors[$field][] = $message;
            }
        }

        return $this->renderer->page($status, $heading, 'project_form', [
            'heading' => $heading,
            'action' => $action,
            'submit' => $submit,
            'cancel' => $cancel,
            'values' => $values,
            'taskCount' => $taskCount,
            'summary' => $summary,
            'errors' => $errors,
            'categories' => TaskValidator::CATEGORIES,
            'csrf' => $this->renderer->csrf(),
        ]);
    }
}
