<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\InvalidTask;
use Actions\Http\ErrorBody;
use Actions\Http\HostGuard;
use Actions\Http\JsonObject;
use PHPUnit\Framework\TestCase;

/** Extractions communes (REST, MCP, portail) : leur comportement est celui des copies d'origine. */
final class HttpHelpersTest extends TestCase
{
    public function testErrorBodyOmitsEmptyDetails(): void
    {
        self::assertSame(['error' => ['code' => 'c', 'message' => 'm']], ErrorBody::of('c', 'm'));
        self::assertSame(['error' => ['code' => 'c', 'message' => 'm', 'details' => ['field' => 'f']]], ErrorBody::of('c', 'm', ['field' => 'f']));
        self::assertSame(
            ['error' => ['code' => 'title_blank', 'message' => 'Vide.', 'details' => ['field' => 'title']]],
            ErrorBody::fromDomain(new InvalidTask('title_blank', 'title', 'Vide.')),
        );
    }

    public function testJsonObjectKeepsListsAndConvertsObjects(): void
    {
        $decoded = json_decode('{"a":{"b":[1,{"c":null}]},"d":[],"e":{}}', false);
        self::assertSame(['a' => ['b' => [1, ['c' => null]]], 'd' => [], 'e' => []], JsonObject::toArray($decoded));
        self::assertSame('x', JsonObject::toArray('x'));
    }

    public function testHostSplitAndAllows(): void
    {
        self::assertSame(['name' => '127.0.0.1', 'port' => 8080], HostGuard::split('127.0.0.1:8080'));
        self::assertSame(['name' => 'localhost', 'port' => null], HostGuard::split('LocalHost'));
        self::assertSame(['name' => '[::1]', 'port' => 80], HostGuard::split('[::1]:80'));
        foreach ([null, '', '[::1', '[]', ':80', 'a b', 'x:y', 'x:'] as $bad) {
            self::assertNull(HostGuard::split($bad), (string) $bad);
        }
        self::assertTrue(HostGuard::allows('localhost:1', ['localhost']));
        self::assertFalse(HostGuard::allows('evil.example', ['localhost']));
        self::assertFalse(HostGuard::allows(null, ['localhost']));
    }
}
