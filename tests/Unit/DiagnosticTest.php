<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\DatabaseUnavailable;
use Actions\Application\Diagnostic;
use PHPUnit\Framework\TestCase;

final class DiagnosticTest extends TestCase
{
    public function testPingIsFixedAndDoesNotTouchDatabase(): void
    {
        $d = new Diagnostic(static fn () => throw new \LogicException('ne doit pas être appelé'));
        self::assertSame(['status' => 'ok', 'service' => 'actions'], $d->ping());
    }

    public function testHealthConvertsPdoFailure(): void
    {
        $d = new Diagnostic(static fn () => throw new \PDOException('secret-host refused'));
        $this->expectException(DatabaseUnavailable::class);
        $this->expectExceptionMessageMatches('/^Base injoignable\.$/');
        $d->health();
    }
}
