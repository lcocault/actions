<?php

declare(strict_types=1);

namespace Actions\Application;

final class TaskIsRequired extends DomainError
{
    /** @param list<array{id:int,title:string}> $dependents */
    public function __construct(int $id, array $dependents)
    {
        parent::__construct(
            'task_is_required',
            'Cette tâche est requise par d\'autres tâches.',
            ['id' => $id, 'dependents' => $dependents],
        );
    }
}
