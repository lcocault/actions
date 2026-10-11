<?php

declare(strict_types=1);

namespace Actions\Application;

final class RequiredTaskNotFound extends DomainError
{
    public function __construct(int $requiredTaskId)
    {
        parent::__construct('required_task_not_found', 'Action requise introuvable.', ['required_task_id' => $requiredTaskId]);
    }
}
