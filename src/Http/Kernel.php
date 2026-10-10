<?php

declare(strict_types=1);

namespace Actions\Http;

use Actions\Mcp\McpAdapter;
use Actions\Portal\PortalAdapter;
use Actions\Rest\RestAdapter;

/** Routage par chemin vers les trois adaptateurs. Aucune règle métier. */
final class Kernel
{
    public function __construct(
        private readonly PortalAdapter $portal,
        private readonly RestAdapter $rest,
        private readonly McpAdapter $mcp,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = $request->path;
        if ($path === '/') {
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
