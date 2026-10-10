<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\Diagnostic;
use Actions\Bootstrap;
use Actions\Http\Request;
use Actions\Infrastructure\Database;

final class HealthTest extends DbTestCase
{
    public function testDiagnosticHealth(): void
    {
        $d = new Diagnostic(fn () => Database::connect($this->config));
        self::assertSame(['status' => 'ok', 'database' => 'ok'], $d->health());
    }

    public function testRestHealthReturns200(): void
    {
        $r = Bootstrap::kernel($this->config)->handle(new Request('GET', '/api/v1/health'));
        self::assertSame(200, $r->status);
        self::assertSame(['status' => 'ok', 'database' => 'ok'], json_decode($r->body, true));
    }
}
