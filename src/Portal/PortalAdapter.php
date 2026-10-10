<?php

declare(strict_types=1);

namespace Actions\Portal;

use Actions\Application\Diagnostic;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Rest\RestAdapter;

final class PortalAdapter
{
    public function __construct(private readonly Diagnostic $diagnostic)
    {
    }

    public function handle(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return new Response(405, ['Allow' => 'GET', 'Content-Type' => 'text/plain; charset=utf-8'], 'Méthode non autorisée.');
        }
        try {
            $ok = $this->diagnostic->ping()['status'] === 'ok';
        } catch (\Throwable $e) {
            error_log('portal: ' . $e->getMessage());

            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Erreur interne.');
        }
        $message = $ok ? "L'application fonctionne." : "L'application ne fonctionne pas.";
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Actions</title></head>'
            . '<body><h1>Actions</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
