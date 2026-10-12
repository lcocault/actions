<?php

declare(strict_types=1);

namespace Actions\Http;

use Actions\Mcp\McpAdapter;
use Actions\Portal\PortalAdapter;
use Actions\Rest\RestAdapter;

/** Routage par chemin vers les trois adaptateurs (portail : `/`, `/tasks`, `/tasks/...`). Aucune règle métier. */
final class Kernel
{
    public function __construct(
        private readonly PortalAdapter $portal,
        private readonly RestAdapter $rest,
        private readonly McpAdapter $mcp,
        private readonly string $basePath = '',
    ) {
    }

    public function handle(Request $request): Response
    {
        // Préfixe d'URL (APP_BASE_PATH) retiré ici : les adaptateurs ne le voient jamais. Hors préfixe : 404.
        if ($this->basePath !== '') {
            if ($request->path === $this->basePath) {
                $request = $request->withPath('/');
            } elseif (str_starts_with($request->path, $this->basePath . '/')) {
                $request = $request->withPath(substr($request->path, strlen($this->basePath)));
            } else {
                return RestAdapter::error(404, 'not_found', 'Ressource introuvable.');
            }
        }
        $path = $request->path;
        if ($path === '/' || $path === '/tasks' || str_starts_with($path, '/tasks/')) {
            return $this->portal->handle($request);
        }
        if ($path === '/mcp') {
            return $this->mcp->handle($request);
        }
        if ($path === '/api/v1' || str_starts_with($path, '/api/v1/')) {
            return $this->rest->handle($request);
        }

        return RestAdapter::error(404, 'not_found', 'Ressource introuvable.');
    }
}
