<?php

declare(strict_types=1);

namespace Actions\Rest;

use Actions\Application\TaskService;
use Actions\Http\Request;
use Actions\Http\Response;

/** Traduit une requête REST en appel de TaskService. Aucune règle métier, aucun SQL; les erreurs de domaine remontent à RestAdapter. */
final class TaskController
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function create(Request $request): Response
    {
        $data = self::body($request);
        if ($data === null) {
            return self::invalidJson();
        }
        $task = $this->tasks->create($data);

        return Response::json(201, $task, ['Location' => '/api/v1/tasks/' . $task['id']]);
    }

    /** La chaîne de requête éventuelle est ignorée (aucun filtre ni option dans cette version). */
    public function list(): Response
    {
        return Response::json(200, $this->tasks->list());
    }

    /** Le corps est contrôlé (400) avant l'appel au cas d'usage, qui contrôle l'existence (404) puis la validation (422). */
    public function update(int $id, Request $request): Response
    {
        $changes = self::body($request);
        if ($changes === null) {
            return self::invalidJson();
        }

        return Response::json(200, $this->tasks->update($id, $changes));
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

    /**
     * Corps = objet JSON (forme du transport seulement; types et règles relèvent de TaskValidator).
     * Retourne null si le corps est vide, invalide, trop profond ou n'est pas un objet.
     *
     * @return array<string,mixed>|null
     */
    private static function body(Request $request): ?array
    {
        try {
            $decoded = json_decode($request->body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!$decoded instanceof \stdClass) {
            return null;
        }
        $data = self::toArray($decoded);

        return is_array($data) ? $data : null;
    }

    /** Objets JSON -> tableaux associatifs; les listes JSON restent des listes. */
    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::toArray(...), $value);
        }

        return $value;
    }

    private static function invalidJson(): Response
    {
        return RestAdapter::error(400, 'invalid_json', 'Le corps doit être un objet JSON.');
    }
}
