<?php

declare(strict_types=1);

namespace Actions\Rest;

use Actions\Application\DatabaseUnavailable;
use Actions\Application\Diagnostic;
use Actions\Application\DomainError;
use Actions\Application\InvalidTask;
use Actions\Http\Request;
use Actions\Http\Response;

/** Routage REST (méthode + chemin) et conversion unique des erreurs en réponses HTTP. */
final class RestAdapter
{
    /** Statut HTTP par code d'erreur de domaine; un code absent de la table est une erreur interne. */
    private const DOMAIN_STATUS = [
        'task_not_found' => 404,
        'required_task_not_found' => 422,
        'dependency_self' => 422,
        'dependency_cycle' => 409,
        'task_is_required' => 409,
    ];

    public function __construct(
        private readonly Diagnostic $diagnostic,
        private readonly TaskController $tasks,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->route($request);
        } catch (DomainError $e) {
            // Les codes de validation forment une famille ouverte : statut par classe, puis par table.
            $status = $e instanceof InvalidTask ? 422 : (self::DOMAIN_STATUS[$e->code()] ?? null);
            if ($status === null) {
                error_log('rest: code de domaine sans statut : ' . $e->code());

                return self::internalError();
            }
            $error = ['code' => $e->code(), 'message' => $e->getMessage()];
            if ($e->details() !== []) {
                $error['details'] = $e->details();
            }

            return Response::json($status, ['error' => $error]);
        } catch (DatabaseUnavailable $e) {
            error_log('rest: base injoignable : ' . ($e->getPrevious()?->getMessage() ?? $e->getMessage()));

            return self::error(503, 'service_unavailable', 'Service indisponible.');
        } catch (\Throwable $e) {
            $state = $e instanceof \PDOException ? ' SQLSTATE ' . (string) ($e->errorInfo[0] ?? $e->getCode()) : '';
            error_log('rest: ' . $e::class . $state . ': ' . $e->getMessage());

            return self::internalError();
        }
    }

    private function route(Request $request): Response
    {
        $path = $request->path;
        $method = $request->method;

        if ($path === '/api/v1/health') {
            if ($method !== 'GET') {
                return self::methodNotAllowed('GET');
            }

            return Response::json(200, $this->diagnostic->health());
        }

        if ($path === '/api/v1/tasks') {
            return match ($method) {
                'GET' => $this->tasks->list(),
                'POST' => $this->tasks->create($request),
                default => self::methodNotAllowed('GET, POST'),
            };
        }

        if (preg_match('#^/api/v1/tasks/([^/]+)$#', $path, $m) === 1) {
            $id = self::id($m[1]);
            if ($id === null) {
                return self::notFound();
            }

            return match ($method) {
                'GET' => $this->tasks->get($id),
                'PATCH' => $this->tasks->update($id, $request),
                'DELETE' => $this->tasks->delete($id),
                default => self::methodNotAllowed('GET, PATCH, DELETE'),
            };
        }

        if (preg_match('#^/api/v1/tasks/([^/]+)/required-actions/([^/]+)$#', $path, $m) === 1) {
            $id = self::id($m[1]);
            $requiredId = self::id($m[2]);
            if ($id === null || $requiredId === null) {
                return self::notFound();
            }

            return match ($method) {
                'PUT' => $this->tasks->addRequiredAction($id, $requiredId),
                'DELETE' => $this->tasks->removeRequiredAction($id, $requiredId),
                default => self::methodNotAllowed('PUT, DELETE'),
            };
        }

        return self::notFound();
    }

    /** Entier positif sans zéro initial tenant dans un entier PHP; sinon null (404). */
    private static function id(string $segment): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/', $segment) !== 1) {
            return null;
        }
        $id = filter_var($segment, FILTER_VALIDATE_INT);

        return is_int($id) ? $id : null;
    }

    private static function notFound(): Response
    {
        return self::error(404, 'not_found', 'Ressource introuvable.');
    }

    private static function methodNotAllowed(string $allow): Response
    {
        return self::error(405, 'method_not_allowed', 'Méthode non autorisée.', ['Allow' => $allow]);
    }

    private static function internalError(): Response
    {
        return self::error(500, 'internal_error', 'Erreur interne.');
    }

    /** @param array<string,string> $headers */
    public static function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return Response::json($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
    }
}
