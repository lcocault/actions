<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Infrastructure\Config;
use Actions\Infrastructure\Migrator;
use Actions\Tests\Support\ArraySession;

/** Préfixe d'URL (APP_BASE_PATH) avec PostgreSQL réel : en-têtes Location et liens des pages de données. */
final class BasePathTasksTest extends DbTestCase
{
    private Kernel $kernel;
    private ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, schema_migrations CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->migrate();
        $this->session = new ArraySession();
        $c = $this->config;
        $config = new Config('test', $c->dbHost, $c->dbPort, $c->dbName, $c->dbUser, $c->dbPassword, [], appBasePath: '/actions');
        $this->kernel = Bootstrap::kernel($config, $this->session);
    }

    public function testHealthIs200UnderPrefix(): void
    {
        self::assertSame(200, $this->kernel->handle(new Request('GET', '/actions/api/v1/health'))->status);
    }

    public function testRestCreateLocationIsPrefixed(): void
    {
        $r = $this->kernel->handle(new Request('POST', '/actions/api/v1/tasks', [], '{"title":"T","category":"Pro"}'));
        self::assertSame(201, $r->status);
        $id = json_decode($r->body, true)['id'];
        self::assertSame("/actions/api/v1/tasks/$id", $r->headers['Location']);
        self::assertSame(200, $this->kernel->handle(new Request('GET', "/actions/api/v1/tasks/$id"))->status);
    }

    public function testPortalCreateRedirectsAndPagesAreLinkedUnderPrefix(): void
    {
        $host = ['host' => '127.0.0.1:8080'];
        $form = ['_csrf' => $this->session->csrfToken(), 'title' => 'Portail', 'category' => 'Perso'];
        $r = $this->kernel->handle(new Request('POST', '/actions/tasks', $host, '', [], $form));
        self::assertSame(303, $r->status);
        self::assertMatchesRegularExpression('#^/actions/tasks/\d+$#', $r->headers['Location']);

        foreach (['/actions/tasks', $r->headers['Location'], $r->headers['Location'] . '/edit', $r->headers['Location'] . '/delete'] as $path) {
            $page = $this->kernel->handle(new Request('GET', $path, $host));
            self::assertSame(200, $page->status, $path);
            preg_match_all('/\b(?:href|action|src)="([^"]*)"/', $page->body, $m);
            foreach ($m[1] as $url) {
                if (str_starts_with($url, '/')) {
                    self::assertStringStartsWith('/actions/', $url, $path);
                }
            }
        }
    }
}
