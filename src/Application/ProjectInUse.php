<?php

declare(strict_types=1);

namespace Actions\Application;

final class ProjectInUse extends DomainError
{
    public function __construct(int $id, int $taskCount)
    {
        parent::__construct(
            'project_in_use',
            'Ce projet contient des tâches : déplacez-les ou supprimez-les avant de supprimer le projet.',
            ['id' => $id, 'task_count' => $taskCount],
        );
    }
}
