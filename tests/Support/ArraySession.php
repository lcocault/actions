<?php

declare(strict_types=1);

namespace Actions\Tests\Support;

use Actions\Portal\Session;

/** Session en mémoire pour les tests (ni en-tête ni cookie). */
final class ArraySession implements Session
{
    private ?string $token = null;
    /** @var ?array<string,mixed> */
    private ?array $flash = null;

    public function csrfToken(): string
    {
        return $this->token ??= bin2hex(random_bytes(32));
    }

    public function flash(array $message): void
    {
        $this->flash = $message;
    }

    public function pullFlash(): ?array
    {
        $m = $this->flash;
        $this->flash = null;

        return $m;
    }

    public function close(): void
    {
    }
}
