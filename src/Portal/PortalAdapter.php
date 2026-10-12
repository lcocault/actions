<?php

declare(strict_types=1);

namespace Actions\Portal;

use Actions\Application\DatabaseUnavailable;
use Actions\Application\DomainError;
use Actions\Application\TaskNotFound;
use Actions\Application\TaskService;
use Actions\Http\DomainStatus;
use Actions\Http\HostGuard;
use Actions\Http\PathId;
use Actions\Http\Request;
use Actions\Http\Response;

/**
 * Portail web : routage, garde de chaque requête (Host, Origin, CSRF), en-têtes de sécurité et conversion
 * des erreurs en pages. Aucune règle métier : les cas d'usage sont ceux de TaskService.
 */
final class PortalAdapter
{
    private const SECURITY_HEADERS = [
        'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
        // `same-origin` et non `no-referrer` : avec `no-referrer`, des navigateurs envoient `Origin: null` sur les POST
        // de formulaire, ce que la garde refuse. Rien n'est transmis aux autres origines.
        'Referrer-Policy' => 'same-origin',
        'Cache-Control' => 'no-store',
    ];

    private readonly Renderer $renderer;
    private readonly TaskPages $pages;

    /** @param list<string> $allowedHosts noms d'hôte en minuscules, sans port */
    public function __construct(
        TaskService $tasks,
        private readonly Session $session,
        private readonly array $allowedHosts,
        ?View $view = null,
        string $basePath = '',
    ) {
        $this->renderer = new Renderer($view ?? new View(prefix: $basePath), $session);
        $this->pages = new TaskPages($tasks, $this->renderer);
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $response = $this->fromException($e);
        } finally {
            $this->session->close();
        }

        return new Response($response->status, $response->headers + self::SECURITY_HEADERS, $response->body);
    }

    private function dispatch(Request $request): Response
    {
        // 1. Host : sans authentification, refuse les noms de domaine inattendus (« DNS rebinding »).
        $host = HostGuard::split($request->header('host'));
        if ($host === null || !in_array($host['name'], $this->allowedHosts, true)) {
            return $this->renderer->error(403, 'Accès refusé', 'Le portail refuse cette adresse d\'accès. Ouvrez-le par http://127.0.0.1 ou http://localhost (avec le même port), ou faites déclarer ce nom d\'hôte par l\'administrateur. Rien n\'a été enregistré.');
        }

        // 2. Méthode et route.
        $handlers = $this->route($request);
        if ($handlers === null) {
            return $this->notFound();
        }
        $handler = $handlers[$request->method] ?? null;
        if ($handler === null) {
            return new Response(405, [
                'Allow' => implode(', ', array_keys($handlers)),
                'Content-Type' => 'text/plain; charset=utf-8',
            ], 'Méthode non autorisée.');
        }

        // 3. Origin et jeton CSRF pour toute requête qui écrit.
        if ($request->method === 'POST') {
            if (!self::originAllowed($request->header('origin'), $host)) {
                return $this->renderer->error(403, 'Requête refusée', 'Requête refusée : elle ne provient pas du portail lui-même (origine différente de l\'adresse du portail). Ouvrez le portail à son adresse habituelle, rechargez la page et recommencez. Rien n\'a été enregistré.');
            }
            $sent = $request->form['_csrf'] ?? null;
            if (!is_string($sent) || $sent === '' || !hash_equals($this->session->csrfToken(), $sent)) {
                return $this->renderer->error(403, 'Requête refusée', 'Requête refusée. Rechargez la page et recommencez. Le formulaire est périmé ou la session a expiré (cookies bloqués ou redémarrage du serveur). Rien n\'a été enregistré : ressaisissez la tâche.');
            }
        }

        return $handler();
    }

    /** @return ?array<string,\Closure():Response> méthode => traitement; null = route inconnue (404) */
    private function route(Request $request): ?array
    {
        $path = $request->path;
        if ($path === '/') {
            return ['GET' => fn (): Response => $this->renderer->redirect('/tasks', 302)];
        }
        if ($path === '/tasks') {
            return ['GET' => fn (): Response => $this->pages->list($request), 'POST' => fn (): Response => $this->pages->create($request)];
        }
        if ($path === '/tasks/new') {
            return ['GET' => fn (): Response => $this->pages->newForm()];
        }
        if (preg_match('#^/tasks/([^/]+)(?:/(edit|delete|status|reopen|required-actions)|/required-actions/([^/]+)/delete)?$#', $path, $m) !== 1) {
            return null;
        }
        $id = PathId::parse($m[1]);
        if ($id === null) {
            return null;
        }
        if (($m[3] ?? '') !== '') { // POST /tasks/{id}/required-actions/{requiredId}/delete
            $requiredId = PathId::parse($m[3]);

            return $requiredId === null ? null : ['POST' => fn (): Response => $this->pages->removeRequiredAction($id, $requiredId)];
        }

        return match ($m[2] ?? '') {
            '' => ['GET' => fn (): Response => $this->pages->show($id), 'POST' => fn (): Response => $this->pages->update($id, $request)],
            'edit' => ['GET' => fn (): Response => $this->pages->editForm($id)],
            'status' => ['POST' => fn (): Response => $this->pages->changeStatus($id, $request)],
            'reopen' => ['GET' => fn (): Response => $this->pages->reopenConfirm($id)],
            'required-actions' => ['POST' => fn (): Response => $this->pages->addRequiredAction($id, $request)],
            default => ['GET' => fn (): Response => $this->pages->deleteConfirm($id), 'POST' => fn (): Response => $this->pages->delete($id)],
        };
    }

    private function notFound(): Response
    {
        return $this->renderer->error(404, 'Introuvable', 'Page introuvable.');
    }

    private function fromException(\Throwable $e): Response
    {
        try {
            if ($e instanceof TaskNotFound) {
                return $this->renderer->error(404, 'Tâche introuvable', 'Tâche introuvable.');
            }
            if ($e instanceof DomainError) {
                $status = DomainStatus::of($e);
                if ($status !== null) {
                    return $this->renderer->error($status, 'Erreur', Messages::for($e));
                }
                error_log('portal: code de domaine sans statut : ' . $e->code());
            } elseif ($e instanceof DatabaseUnavailable) {
                error_log('portal: base injoignable : ' . ($e->getPrevious()?->getMessage() ?? $e->getMessage()));

                return $this->renderer->error(503, 'Service indisponible', 'Service indisponible.');
            } else {
                $state = $e instanceof \PDOException ? ' SQLSTATE ' . (string) ($e->errorInfo[0] ?? $e->getCode()) : '';
                error_log('portal: ' . $e::class . $state . ': ' . $e->getMessage());
            }

            return $this->renderer->error(500, 'Erreur interne', 'Erreur interne.');
        } catch (\Throwable $inner) {
            // Le rendu lui-même a échoué : réponse minimale, sans détail.
            error_log('portal: rendu de la page d\'erreur impossible : ' . $inner::class . ': ' . $inner->getMessage());

            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Erreur interne.');
        }
    }

    /**
     * En-tête Origin (requêtes POST) : absent = accepté (le jeton CSRF reste exigé); présent = même hôte
     * et même port que `Host`.
     *
     * @param array{name:string,port:?int} $host
     */
    private static function originAllowed(?string $origin, array $host): bool
    {
        if ($origin === null) {
            return true;
        }
        $parts = parse_url($origin);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            return false;
        }
        $default = $parts['scheme'] === 'https' ? 443 : 80;

        return strtolower($parts['host']) === $host['name']
            && ($parts['port'] ?? $default) === ($host['port'] ?? $default);
    }
}
