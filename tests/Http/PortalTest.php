<?php

declare(strict_types=1);

namespace Actions\Tests\Http;

final class PortalTest extends HttpTestCase
{
    public function testHomePageIsStaticAndWorksWithoutDatabase(): void
    {
        $r = $this->call('GET', '/');
        self::assertSame(200, $r->status);
        self::assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
        self::assertStringContainsString("L'application fonctionne.", htmlspecialchars_decode($r->body, ENT_QUOTES));
    }

    public function testHomeRejectsPost(): void
    {
        self::assertSame(405, $this->call('POST', '/')->status);
    }
}
