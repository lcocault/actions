<?php

declare(strict_types=1);

namespace Actions\Rest;

use Actions\Application\DatabaseUnavailable;
use Actions\Application\Diagnostic;
use Actions\Http\Request;
use Actions\Http\Response;

final class RestAdapter
{
    public function __construct(private readonly Diagnostic $diagnostic)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            if ($request->path !== '/api/v1/health') {
                return self::error(404, 'not_found', 'Ressource introuvable.');
            }
            if ($request->method !== 'GET') {
                return self::error(405, 'method_not_allowed', 'Méthode non autorisée.', ['Allow' => 'GET']);
            }
            try {
                return Response::json(200, $this->diagnostic->health());
            } catch (DatabaseUnavailable $e) {
                error_log('health: ' . ($e->getPrevious()?->getMessage() ?? $e->getMessage()));

                return self::error(503, 'service_unavailable', 'Service indisponible.');
            }
        } catch (\Throwable $e) {
            error_log('rest: ' . $e::class . ': ' . $e->getMessage());

            return self::error(500, 'internal_error', 'Erreur interne.');
        }
    }

    /** @param array<string,string> $headers */
    public static function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return Response::json($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
    }
}
