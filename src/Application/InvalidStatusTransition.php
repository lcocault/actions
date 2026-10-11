<?php

declare(strict_types=1);

namespace Actions\Application;

final class InvalidStatusTransition extends DomainError
{
    public function __construct(int $id, TaskStatus $from, TaskStatus $to)
    {
        parent::__construct(
            'status_transition_invalid',
            'Changement d\'état non permis.',
            ['id' => $id, 'from' => $from->value, 'to' => $to->value],
        );
    }
}
