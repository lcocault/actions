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
use Actions\Portal\PortalAdapter;
use Actions\Rest\RestAdapter;

final class Bootstrap
{
    /** Cas d'usage des tâches (les adaptateurs le recevront dans les tranches REST/MCP/portail). */
    public static function taskService(Config $config): TaskService
    {
        return new TaskService(new TaskRepository(static fn (): \PDO => Database::connect($config)));
    }

    public static function kernel(Config $config): Kernel
    {
        $diagnostic = new Diagnostic(static fn (): \PDO => Database::connect($config));

        return new Kernel(
            new PortalAdapter($diagnostic),
            new RestAdapter($diagnostic),
            new McpAdapter($diagnostic, $config->mcpAllowedOrigins),
        );
    }
}
