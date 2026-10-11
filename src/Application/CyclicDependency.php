<?php

declare(strict_types=1);

namespace Actions\Application;

final class CyclicDependency extends DomainError
{
    public function __construct(int $taskId, int $requiredTaskId)
    {
        parent::__construct(
            'dependency_cycle',
            'Ce lien créerait une dépendance circulaire.',
            ['task_id' => $taskId, 'required_task_id' => $requiredTaskId],
        );
    }
}
