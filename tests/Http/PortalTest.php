<?php

declare(strict_types=1);

namespace Actions\Tests\Http;

/** Portail sans base : redirection de la racine, garde Host, méthode non autorisée, base injoignable. */
final class PortalTest extends HttpTestCase
{
    private const HOST = ['Host' => '127.0.0.1:8080'];

    public function testHomeRedirectsToTaskList(): void
    {
        $r = $this->call('GET', '/', self::HOST);
        self::assertSame(302, $r->status);
        self::assertSame('/tasks', $r->headers['Location']);
    }

    public function testHomeRejectsPost(): void
    {
        $r = $this->call('POST', '/', self::HOST);
        self::assertSame(405, $r->status);
        self::assertSame('GET', $r->headers['Allow']);
    }

    public function testHostOutsideListIsRefused(): void
    {
        foreach (['evil.example', 'evil.example:8080', '127.0.0.1.evil.example', ''] as $host) {
            self::assertSame(403, $this->call('GET', '/', ['Host' => $host])->status, $host);
        }
        self::assertSame(403, $this->call('GET', '/')->status, 'Host absent');
    }

    public function testDefaultHostsAreAccepted(): void
    {
        foreach (['127.0.0.1', '127.0.0.1:8080', 'localhost:3000', 'LOCALHOST', '[::1]', '[::1]:8080'] as $host) {
            self::assertSame(302, $this->call('GET', '/', ['Host' => $host])->status, $host);
        }
    }

    public function testListWithUnreachableDatabaseIs503WithoutDetail(): void
    {
        $r = $this->call('GET', '/tasks', self::HOST);
        self::assertSame(503, $r->status);
        self::assertStringContainsString('Service indisponible', $r->body);
        foreach (['secret_db', 'secret_user', 'secret_pwd', 'PDO', 'SQLSTATE'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
    }

    public function testSecurityHeadersAreOnEveryResponse(): void
    {
        foreach ([$this->call('GET', '/', self::HOST), $this->call('GET', '/', ['Host' => 'evil.example']), $this->call('GET', '/tasks/abc', self::HOST)] as $r) {
            self::assertStringContainsString("default-src 'none'", $r->headers['Content-Security-Policy']);
            self::assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
            self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
            self::assertSame('same-origin', $r->headers['Referrer-Policy']);
            self::assertSame('no-store', $r->headers['Cache-Control']);
        }
    }

    public function testUnknownPortalPathsAre404Html(): void
    {
        foreach (['/tasks/abc', '/tasks/0', '/tasks/01', '/tasks/1/unknown', '/tasks/'] as $path) {
            $r = $this->call('GET', $path, self::HOST);
            self::assertSame(404, $r->status, $path);
            self::assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
        }
    }

    public function testMethodNotAllowedListsAllow(): void
    {
        $r = $this->call('DELETE', '/tasks', self::HOST);
        self::assertSame(405, $r->status);
        self::assertSame('GET, POST', $r->headers['Allow']);
        self::assertSame('GET', $this->call('POST', '/tasks/new', self::HOST)->headers['Allow']);
        self::assertSame('GET', $this->call('POST', '/tasks/1/edit', self::HOST)->headers['Allow']);
    }

    public function testRestAndMcpPathsAreNotCapturedByPortal(): void
    {
        self::assertSame('application/json', $this->call('GET', '/api/v1/health')->headers['Content-Type']);
        self::assertSame('application/json', $this->call('GET', '/nowhere')->headers['Content-Type']);
    }
}
