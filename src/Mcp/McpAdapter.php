<?php

declare(strict_types=1);

namespace Actions\Mcp;

use Actions\Application\Diagnostic;
use Actions\Http\Request;
use Actions\Http\Response;

/** MCP Streamable HTTP minimal : POST /mcp, sans session, JSON seul. */
final class McpAdapter
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26'];
    public const VERSION = '0.1.0';
    private const MAX_BODY = 1_048_576;

    /** @param list<string> $allowedOrigins */
    public function __construct(
        private readonly Diagnostic $diagnostic,
        private readonly array $allowedOrigins = [],
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->process($request);
        } catch (\Throwable $e) {
            error_log('mcp: ' . $e::class . ': ' . $e->getMessage());

            return $this->rpcError(null, -32603, 'Erreur interne.');
        }
    }

    private function process(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return new Response(405, ['Allow' => 'POST', 'Content-Type' => 'text/plain; charset=utf-8'], 'Méthode non autorisée.');
        }
        $origin = $request->header('Origin');
        if ($origin !== null && !in_array($origin, $this->allowedOrigins, true)) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Origine non autorisée.');
        }
        $type = strtolower(trim(explode(';', $request->header('Content-Type') ?? '')[0]));
        if ($type !== 'application/json') {
            return $this->rpcError(null, -32600, 'Content-Type application/json requis.', 415);
        }
        if (strlen($request->body) > self::MAX_BODY) {
            return $this->rpcError(null, -32600, 'Message trop volumineux.', 400);
        }
        try {
            $message = json_decode($request->body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->rpcError(null, -32700, 'JSON invalide.', 400);
        }
        if (is_array($message)) {
            return $this->rpcError(null, -32600, 'Les batchs ne sont pas supportés.', 400);
        }
        if (!$message instanceof \stdClass
            || ($message->jsonrpc ?? null) !== '2.0'
            || !is_string($message->method ?? null)
            || (property_exists($message, 'params') && !($message->params instanceof \stdClass))
        ) {
            return $this->rpcError(null, -32600, 'Message JSON-RPC invalide.', 400);
        }
        $isNotification = !property_exists($message, 'id');
        $id = $message->id ?? null;
        if (!$isNotification && !is_int($id) && !is_string($id)) {
            return $this->rpcError(null, -32600, 'Identifiant JSON-RPC invalide.', 400);
        }
        if ($isNotification) {
            return new Response(202);
        }
        $params = $message->params ?? new \stdClass();

        return match ($message->method) {
            'initialize' => $this->initialize($id, $params),
            'tools/list' => $this->result($id, ['tools' => [[
                'name' => 'ping',
                'description' => 'Diagnostic : renvoie un résultat fixe.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ]]]),
            'tools/call' => $this->callTool($id, $params),
            default => $this->rpcError($id, -32601, 'Méthode inconnue.'),
        };
    }

    private function initialize(int|string $id, \stdClass $params): Response
    {
        $requested = $params->protocolVersion ?? null;
        if (!is_string($requested)) {
            return $this->rpcError($id, -32602, 'protocolVersion requis.');
        }
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0];

        return $this->result($id, [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => 'actions', 'version' => self::VERSION],
        ]);
    }

    private function callTool(int|string $id, \stdClass $params): Response
    {
        $name = $params->name ?? null;
        if ($name !== 'ping') {
            return $this->rpcError($id, -32602, 'Outil inconnu.');
        }
        $arguments = $params->arguments ?? new \stdClass();
        if (!$arguments instanceof \stdClass || (array) $arguments !== []) {
            return $this->rpcError($id, -32602, 'Arguments invalides : ping n\'en accepte aucun.');
        }

        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($this->diagnostic->ping(), JSON_THROW_ON_ERROR),
            ]],
            'isError' => false,
        ]);
    }

    private function result(int|string $id, mixed $result): Response
    {
        return Response::json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function rpcError(int|string|null $id, int $code, string $message, int $status = 200): Response
    {
        return Response::json($status, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
