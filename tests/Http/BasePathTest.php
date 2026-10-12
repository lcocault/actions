<?php

declare(strict_types=1);

namespace Actions\Tests\Http;

use Actions\Portal\NativeSession;

/** Préfixe d'URL (APP_BASE_PATH) : sans base de données (port injoignable). Les créations sont dans BasePathTasksTest. */
final class BasePathTest extends HttpTestCase
{
    private const HOST = ['host' => '127.0.0.1:8080'];
    private const PREFIX = '/actions';

    public function testRestIsReachedUnderPrefix(): void
    {
        // 503 (et non 404) : l'adaptateur REST a été atteint, la base est volontairement injoignable.
        $r = $this->call('GET', '/actions/api/v1/health', basePath: self::PREFIX);
        self::assertSame(503, $r->status);
        self::assertSame('service_unavailable', $this->decode($r)['error']['code']);
    }

    public function testMcpPingUnderPrefix(): void
    {
        $r = $this->call('POST', '/actions/mcp', self::HOST + ['content-type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"ping"}}', basePath: self::PREFIX);
        self::assertSame(200, $r->status);
        self::assertFalse($this->decode($r)['result']['isError']);
    }

    public function testRootRedirectsToPrefixedList(): void
    {
        foreach (['/actions', '/actions/'] as $path) {
            $r = $this->call('GET', $path, self::HOST, basePath: self::PREFIX);
            self::assertSame(302, $r->status, $path);
            self::assertSame('/actions/tasks', $r->headers['Location'], $path);
        }
    }

    public function testRenderedPortalPageHasNoUnprefixedLocalUrl(): void
    {
        $r = $this->call('GET', '/actions/tasks/new', self::HOST, basePath: self::PREFIX);
        self::assertSame(200, $r->status);
        self::assertGreaterThan(0, preg_match_all('/\b(?:href|action|src)="([^"]*)"/', $r->body, $m));
        $local = array_filter($m[1], static fn (string $u): bool => str_starts_with($u, '/'));
        self::assertNotEmpty($local);
        foreach ($local as $url) {
            self::assertStringStartsWith('/actions/', $url);
        }
        self::assertStringContainsString('/actions/assets/portal.css', $r->body);
    }

    public function testOutsidePrefixIsJson404WithoutReachingAdapters(): void
    {
        foreach (['/tasks', '/', '/api/v1/health', '/mcp', '/actionsfoo/tasks', '/actionsfoo', '/Actions/tasks', '/actions/../tasks', '/other/actions/tasks'] as $path) {
            // `Host` étranger : un adaptateur répondrait 403; le 404 prouve qu'aucun n'a été appelé.
            $r = $this->call('GET', $path, ['host' => 'evil.example'], basePath: self::PREFIX);
            self::assertSame(404, $r->status, $path);
            self::assertSame('application/json', $r->headers['Content-Type'], $path);
            self::assertSame('not_found', $this->decode($r)['error']['code'], $path);
        }
    }

    public function testUnknownPathUnderPrefixIsStill404(): void
    {
        self::assertSame(404, $this->call('GET', '/actions/inconnu', self::HOST, basePath: self::PREFIX)->status);
    }

    public function testForeignHostIsStillRefusedUnderPrefix(): void
    {
        self::assertSame(403, $this->call('GET', '/actions/tasks', ['host' => 'evil.example'], basePath: self::PREFIX)->status);
    }

    public function testNoPrefixConfiguredKeepsPrefixedPathsUnknown(): void
    {
        self::assertSame(404, $this->call('GET', '/actions/api/v1/health')->status);
        self::assertSame(503, $this->call('GET', '/api/v1/health')->status);
    }

    public function testSessionCookiePathFollowsPrefix(): void
    {
        self::assertSame('/actions', (new NativeSession(false, '/actions'))->options()['cookie_path']);
        self::assertSame('/', (new NativeSession())->options()['cookie_path']);
    }
}
