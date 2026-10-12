<?php

declare(strict_types=1);

namespace Actions\Portal;

/**
 * Session minimale du portail : jeton CSRF et un message flash. Aucun compte.
 *
 * Message flash : {type: success|error, text: string, link?: {label: string, path: string}}.
 */
interface Session
{
    public function csrfToken(): string;

    /** @param array<string,mixed> $message */
    public function flash(array $message): void;

    /** @return ?array<string,mixed> lu et supprimé */
    public function pullFlash(): ?array;

    public function close(): void;
}
