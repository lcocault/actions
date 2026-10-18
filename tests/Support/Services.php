<?php

declare(strict_types=1);

namespace Actions\Tests\Support;

use Actions\Application\ProjectOverviewService;
use Actions\Application\ProjectRepository;
use Actions\Application\ProjectService;
use Actions\Application\TaskRepository;
use Actions\Application\TaskService;

/** Graphe de services sur une connexion fournie (les deux dépôts partagent la même, donc la même transaction). */
final class Services
{
    /** @param \Closure():\PDO $connect */
    public static function tasks(\Closure $connect): TaskService
    {
        return new TaskService(new TaskRepository($connect), new ProjectRepository($connect));
    }

    /** @param \Closure():\PDO $connect */
    public static function projects(\Closure $connect): ProjectService
    {
        return new ProjectService(new ProjectRepository($connect));
    }

    /** @param \Closure():\PDO $connect */
    public static function overview(\Closure $connect, ?\Closure $today = null): ProjectOverviewService
    {
        return new ProjectOverviewService(new ProjectRepository($connect), new TaskRepository($connect), $today);
    }
}
