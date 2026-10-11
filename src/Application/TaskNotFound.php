<?php

declare(strict_types=1);

namespace Actions\Application;

final class TaskNotFound extends DomainError
{
    public function __construct(int $id)
    {
        parent::__construct('task_not_found', 'Tâche introuvable.', ['id' => $id]);
    }
}
