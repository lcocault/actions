<?php

declare(strict_types=1);

namespace Actions\Tests\Http;

use Actions\Bootstrap;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Config;
use PHPUnit\Framework\TestCase;

abstract class HttpTestCase extends TestCase
{
    /** @param list<string> $origins */
    protected function call(string $method, string $path, array $headers = [], string $body = '', array $origins = [], int $dbPort = 1): Response
    {
        $config = new Config('test', '127.0.0.1', $dbPort, 'secret_db', 'secret_user', 'secret_pwd', $origins);

        return Bootstrap::kernel($config)->handle(new Request($method, $path, array_change_key_case($headers), $body));
    }

    /** @return array<string,mixed> */
    protected function mcp(array|string $message, array $headers = ['Content-Type' => 'application/json'], array $origins = []): Response
    {
        return $this->call('POST', '/mcp', $headers, is_string($message) ? $message : json_encode($message), $origins);
    }

    protected function decode(Response $r): array
    {
        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }
}
