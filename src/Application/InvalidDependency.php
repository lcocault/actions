<?php

declare(strict_types=1);

namespace Actions\Application;

/** Auto-dépendance. */
final class InvalidDependency extends DomainError
{
    public function __construct(int $taskId)
    {
        parent::__construct('dependency_self', 'Une tâche ne peut pas dépendre d\'elle-même.', ['task_id' => $taskId]);
    }
}
