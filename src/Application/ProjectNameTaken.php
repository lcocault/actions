<?php

declare(strict_types=1);

namespace Actions\Application;

/** Un autre projet porte déjà ce nom (comparaison insensible à la casse, arbitrée par la base). */
final class ProjectNameTaken extends DomainError
{
    public function __construct()
    {
        parent::__construct('project_name_taken', 'Un projet porte déjà ce nom (la casse ne compte pas).', ['field' => 'name']);
    }
}
