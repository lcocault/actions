<?php

declare(strict_types=1);

namespace Actions\Portal;

/** Session PHP native : cookie `actions_session` (HttpOnly, SameSite=Strict, Secure si HTTPS), durée = navigateur. */
final class NativeSession implements Session
{
    private const COOKIE = 'actions_session';

    public function __construct(private readonly bool $secure = false)
    {
    }

    public function csrfToken(): string
    {
        $this->start();
        $token = $_SESSION['csrf'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf'] = $token;
        }

        return $token;
    }

    public function flash(array $message): void
    {
        $this->start();
        $_SESSION['flash'] = $message;
    }

    public function pullFlash(): ?array
    {
        // Sans cookie de session, aucun message n'est en attente : on évite de créer une session.
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[self::COOKIE])) {
            return null;
        }
        $this->start();
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($message) ? $message : null;
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::COOKIE);
        if (!session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_secure' => $this->secure,
            'cookie_lifetime' => 0,
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'cache_limiter' => '', // les en-têtes de cache sont posés par le portail
        ])) {
            throw new \RuntimeException('Session indisponible.');
        }
    }
}
