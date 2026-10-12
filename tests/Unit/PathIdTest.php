<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Http\PathId;
use PHPUnit\Framework\TestCase;

final class PathIdTest extends TestCase
{
    public function testValidIds(): void
    {
        self::assertSame(1, PathId::parse('1'));
        self::assertSame(120, PathId::parse('120'));
        self::assertSame(PHP_INT_MAX, PathId::parse((string) PHP_INT_MAX));
    }

    public function testInvalidIds(): void
    {
        foreach (['0', '01', '-1', '+1', '1.5', 'abc', '', ' 1', '9223372036854775808', '99999999999999999999'] as $bad) {
            self::assertNull(PathId::parse($bad), $bad);
        }
    }
}
