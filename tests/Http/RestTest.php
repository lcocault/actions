<?php

declare(strict_types=1);

namespace Actions\Tests\Http;

final class RestTest extends HttpTestCase
{
    public function testHealthReturns503WithoutSensitiveDetailWhenDatabaseIsDown(): void
    {
        $r = $this->call('GET', '/api/v1/health');
        self::assertSame(503, $r->status);
        self::assertSame('application/json', $r->headers['Content-Type']);
        self::assertSame(['error' => ['code' => 'service_unavailable', 'message' => 'Service indisponible.']], $this->decode($r));
        foreach (['127.0.0.1', 'secret_db', 'secret_user', 'secret_pwd', 'SQLSTATE'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
    }

    public function testHealthRejectsOtherMethods(): void
    {
        $r = $this->call('POST', '/api/v1/health');
        self::assertSame(405, $r->status);
        self::assertSame('GET', $r->headers['Allow']);
        self::assertSame('method_not_allowed', $this->decode($r)['error']['code']);
    }

    public function testUnknownPathsReturn404(): void
    {
        foreach (['/api/v1/nope', '/api/v1', '/ailleurs'] as $path) {
            $r = $this->call('GET', $path);
            self::assertSame(404, $r->status, $path);
            self::assertSame('not_found', $this->decode($r)['error']['code']);
        }
    }
}
