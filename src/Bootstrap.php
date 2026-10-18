<?php

declare(strict_types=1);

namespace Actions;

use Actions\Application\Diagnostic;
use Actions\Application\ProjectOverviewService;
use Actions\Application\ProjectRepository;
use Actions\Application\ProjectService;
use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Http\Kernel;
use Actions\Infrastructure\Config;
use Actions\Infrastructure\Database;
use Actions\Mcp\McpAdapter;
use Actions\Portal\NativeSession;
use Actions\Portal\PortalAdapter;
use Actions\Portal\Session;
use Actions\Rest\ProjectController;
use Actions\Rest\RestAdapter;
use Actions\Rest\TaskController;

final class Bootstrap
{
    /**
     * Connexion paresseuse partagée : les dépôts de tâches et de projets d'un même graphe de services
     * écrivent dans la même transaction (résolution du nom de projet puis écriture de la tâche).
     *
     * @return \Closure():\PDO
     */
    private static function connection(Config $config): \Closure
    {
        $pdo = null;

        return static function () use ($config, &$pdo): \PDO {
            return $pdo ??= Database::connect($config);
        };
    }

    /** Cas d'usage des tâches (REST, MCP et portail l'utilisent). */
    public static function taskService(Config $config): TaskService
    {
        $connect = self::connection($config);

        return new TaskService(new TaskRepository($connect), new ProjectRepository($connect));
    }

    /** Cas d'usage des projets (REST, MCP et portail l'utilisent). */
    public static function projectService(Config $config): ProjectService
    {
        return new ProjectService(new ProjectRepository(self::connection($config)));
    }

    /** Vue projet (synthèse et matrice). $today : jour de référence AAAA-MM-JJ, injectable (défaut : jour courant en Europe/Paris). */
    public static function projectOverviewService(Config $config, ?\Closure $today = null): ProjectOverviewService
    {
        $connect = self::connection($config);

        return new ProjectOverviewService(new ProjectRepository($connect), new TaskRepository($connect), $today);
    }

    /**
     * $session : NativeSession par défaut (les tests passent une session en mémoire).
     * $today : jour de référence de la vue projet, fixé par les tests.
     *
     * @param ?\Closure():string $today
     */
    public static function kernel(Config $config, ?Session $session = null, ?\Closure $today = null): Kernel
    {
        $diagnostic = new Diagnostic(static fn (): \PDO => Database::connect($config));
        $tasks = self::taskService($config);
        $projects = self::projectService($config);
        $overview = self::projectOverviewService($config, $today);
        $basePath = $config->appBasePath;
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';

        return new Kernel(
            new PortalAdapter(
                $tasks,
                $projects,
                $overview,
                $session ?? new NativeSession($https, $basePath !== '' ? $basePath : '/'),
                $config->portalAllowedHosts,
                basePath: $basePath,
            ),
            new RestAdapter($diagnostic, new TaskController($tasks, $basePath), new ProjectController($projects, $basePath)),
            new McpAdapter($diagnostic, $tasks, $projects, $config->mcpAllowedOrigins, $config->mcpAllowedHosts),
            $basePath,
        );
    }
}
