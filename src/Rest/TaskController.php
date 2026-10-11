<?php

declare(strict_types=1);

namespace Actions\Rest;

use Actions\Application\TaskService;
use Actions\Http\Response;

/** Traduit une requête REST en appel de TaskService. Aucune règle métier, aucun SQL; les erreurs de domaine remontent à RestAdapter. */
final class TaskController
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function get(int $id): Response
    {
        return Response::json(200, $this->tasks->get($id));
    }

    public function delete(int $id): Response
    {
        $this->tasks->delete($id);

        return new Response(204);
    }

    public function addRequiredAction(int $id, int $requiredId): Response
    {
        return Response::json(200, $this->tasks->addRequiredAction($id, $requiredId));
    }

    public function removeRequiredAction(int $id, int $requiredId): Response
    {
        return Response::json(200, $this->tasks->removeRequiredAction($id, $requiredId));
    }
}
