<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Actions\Bootstrap;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Config;

try {
    $config = Config::load();
    ini_set('display_errors', $config->appEnv === 'local' ? '1' : '0');
    Bootstrap::kernel($config)->handle(Request::fromGlobals())->send();
} catch (Throwable $e) {
    error_log('bootstrap: ' . $e::class . ': ' . $e->getMessage());
    (new Response(500, ['Content-Type' => 'application/json'], '{"error":{"code":"internal_error","message":"Erreur interne."}}'))->send();
}
