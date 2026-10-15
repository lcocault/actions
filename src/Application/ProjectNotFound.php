<?php

declare(strict_types=1);

namespace Actions\Application;

final class ProjectNotFound extends DomainError
{
    public function __construct(int $id)
    {
        parent::__construct('project_not_found', 'Projet introuvable.', ['id' => $id]);
    }
}
