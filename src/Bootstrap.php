<?php

declare(strict_types=1);

namespace Actions;

use Actions\Application\Diagnostic;
use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Http\Kernel;
use Actions\Infrastructure\Config;
use Actions\Infrastructure\Database;
use Actions\Mcp\McpAdapter;
use Actions\Portal\NativeSession;
use Actions\Portal\PortalAdapter;
use Actions\Portal\Session;
use Actions\Rest\RestAdapter;
use Actions\Rest\TaskController;

final class Bootstrap
{
    /** Cas d'usage des tâches (REST, MCP et portail l'utilisent). */
    public static function taskService(Config $config): TaskService
    {
        return new TaskService(new TaskRepository(static fn (): \PDO => Database::connect($config)));
    }

    /** $session : NativeSession par défaut (les tests passent une session en mémoire). */
    public static function kernel(Config $config, ?Session $session = null): Kernel
    {
        $diagnostic = new Diagnostic(static fn (): \PDO => Database::connect($config));
        $tasks = self::taskService($config);
        $basePath = $config->appBasePath;
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';

        return new Kernel(
            new PortalAdapter(
                $tasks,
                $session ?? new NativeSession($https, $basePath !== '' ? $basePath : '/'),
                $config->portalAllowedHosts,
                basePath: $basePath,
            ),
            new RestAdapter($diagnostic, new TaskController($tasks, $basePath)),
            new McpAdapter($diagnostic, $tasks, $config->mcpAllowedOrigins, $config->mcpAllowedHosts),
            $basePath,
        );
    }
}
