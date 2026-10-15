<?php

declare(strict_types=1);

namespace Actions\Rest;

use Actions\Application\ProjectService;
use Actions\Http\Request;
use Actions\Http\Response;

/** Traduit une requête REST en appel de ProjectService. Aucune règle métier, aucun SQL; les erreurs de domaine remontent à RestAdapter. */
final class ProjectController
{
    public function __construct(private readonly ProjectService $projects, private readonly string $basePath = '')
    {
    }

    public function list(): Response
    {
        return Response::json(200, $this->projects->list());
    }

    public function create(Request $request): Response
    {
        $data = JsonBody::object($request);
        if ($data === null) {
            return JsonBody::invalid();
        }
        $project = $this->projects->create($data);

        return Response::json(201, $project, ['Location' => $this->basePath . '/api/v1/projects/' . $project['id']]);
    }

    public function get(int $id): Response
    {
        return Response::json(200, $this->projects->get($id));
    }

    /** Corps contrôlé (400) avant l'appel au cas d'usage, qui contrôle l'existence (404) puis la validation (422/409). */
    public function update(int $id, Request $request): Response
    {
        $changes = JsonBody::object($request);
        if ($changes === null) {
            return JsonBody::invalid();
        }
        $result = $this->projects->update($id, $changes);

        return Response::json(200, $result['project'] + ['tasks_recategorized' => $result['tasks_recategorized']]);
    }

    public function delete(int $id): Response
    {
        $this->projects->delete($id);

        return new Response(204);
    }
}
