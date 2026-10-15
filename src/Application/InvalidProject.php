<?php

declare(strict_types=1);

namespace Actions\Application;

/** Entrée de projet invalide en soi (un `code` par règle). Détails : `field`. */
final class InvalidProject extends DomainError
{
    public function __construct(string $code, string $field, string $message)
    {
        parent::__construct($code, $message, ['field' => $field]);
    }
}
