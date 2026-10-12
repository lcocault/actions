<?php

declare(strict_types=1);

namespace Actions\Rest;

use Actions\Application\DatabaseUnavailable;
use Actions\Application\Diagnostic;
use Actions\Application\DomainError;
use Actions\Http\DomainStatus;
use Actions\Http\PathId;
use Actions\Http\Request;
use Actions\Http\Response;

/** Routage REST (méthode + chemin) et conversion unique des erreurs en réponses HTTP. */
final class RestAdapter
{
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
            $status = DomainStatus::of($e);
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
                'GET' => $this->tasks->list($request),
                'POST' => $this->tasks->create($request),
                default => self::methodNotAllowed('GET, POST'),
            };
        }

        if (preg_match('#^/api/v1/tasks/([^/]+)$#', $path, $m) === 1) {
            $id = PathId::parse($m[1]);
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
            $id = PathId::parse($m[1]);
            $requiredId = PathId::parse($m[2]);
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
