<?php

declare(strict_types=1);

namespace Actions\Portal;

use Actions\Http\Response;

/** Construit les réponses HTML du portail (page, erreur, redirection) à partir des gabarits et de la session. */
final class Renderer
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
    ) {
    }

    /** @param array<string,mixed> $vars */
    public function page(int $status, string $title, string $template, array $vars = []): Response
    {
        $content = $this->view->render($template, $vars);
        $html = $this->view->render('layout', [
            'title' => $title,
            'flash' => $this->session->pullFlash(),
            'content' => $content,
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public function error(int $status, string $heading, string $message): Response
    {
        return $this->page($status, $heading, 'error', ['heading' => $heading, 'message' => $message]);
    }

    /** Redirection après écriture (Post/Redirect/Get). */
    public function redirect(string $path, int $status = 303): Response
    {
        return new Response($status, ['Location' => $this->view->path($path)]);
    }

    public function csrf(): string
    {
        return $this->session->csrfToken();
    }

    /** @param array<string,mixed> $message */
    public function flash(array $message): void
    {
        $this->session->flash($message);
    }
}
