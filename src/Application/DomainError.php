<?php

declare(strict_types=1);

namespace Actions\Application;

/** Erreur de domaine : `code()` est stable et contractuel; le message est court et sans détail technique. */
abstract class DomainError extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
