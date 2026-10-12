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

    /**
     * Envoie un message à POST /mcp avec `Host: 127.0.0.1:8080` et `Content-Type: application/json` par défaut.
     * $headers remplace ou ajoute des en-têtes; une valeur null supprime l'en-tête par défaut.
     *
     * @param array<string,?string> $headers
     * @param list<string> $origins
     */
    protected function mcp(array|string $message, array $headers = [], array $origins = []): Response
    {
        $merged = ['host' => '127.0.0.1:8080', 'content-type' => 'application/json'];
        foreach ($headers as $name => $value) {
            if ($value === null) {
                unset($merged[strtolower($name)]);
            } else {
                $merged[strtolower($name)] = $value;
            }
        }

        return $this->call('POST', '/mcp', $merged, is_string($message) ? $message : json_encode($message), $origins);
    }

    protected function decode(Response $r): array
    {
        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }
}
