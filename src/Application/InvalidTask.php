<?php

declare(strict_types=1);

namespace Actions\Application;

/** Entrée invalide en soi (un `code` par règle). Détails : `field`. */
final class InvalidTask extends DomainError
{
    public function __construct(string $code, string $field, string $message)
    {
        parent::__construct($code, $message, ['field' => $field]);
    }
}
