<?php

declare(strict_types=1);

namespace Actions\Mcp;

use Actions\Application\DomainError;

/** Erreur de forme d'un appel d'outil (identifiant incorrect, confirmation absente), que TaskService ne peut pas voir. */
final class InvalidToolCall extends DomainError
{
    public function __construct(string $code, string $field, string $message)
    {
        parent::__construct($code, $message, ['field' => $field]);
    }
}
