<?php

declare(strict_types=1);

namespace Actions\Mcp;

use Actions\Application\DatabaseUnavailable;
use Actions\Application\Diagnostic;
use Actions\Application\DomainError;
use Actions\Application\ProjectService;
use Actions\Application\TaskService;
use Actions\Http\DomainStatus;
use Actions\Http\ErrorBody;
use Actions\Http\HostGuard;
use Actions\Http\JsonObject;
use Actions\Http\Request;
use Actions\Http\Response;

/** MCP Streamable HTTP minimal : POST /mcp, sans session, JSON seul. */
final class McpAdapter
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26'];
    public const VERSION = '0.2.0';
    private const MAX_BODY = 1_048_576;

    private readonly TaskTools $tools;

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedHosts noms d'hôte en minuscules, sans port
     */
    public function __construct(
        private readonly Diagnostic $diagnostic,
        TaskService $tasks,
        ProjectService $projects,
        private readonly array $allowedOrigins = [],
        private readonly array $allowedHosts = [],
    ) {
        $this->tools = new TaskTools($tasks, $projects);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->process($request);
        } catch (\Throwable $e) {
            $state = $e instanceof \PDOException ? ' SQLSTATE ' . (string) ($e->errorInfo[0] ?? $e->getCode()) : '';
            error_log('mcp: ' . $e::class . $state . ': ' . $e->getMessage());

            return $this->rpcError(null, -32603, 'Erreur interne.');
        }
    }

    private function process(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return new Response(405, ['Allow' => 'POST', 'Content-Type' => 'text/plain; charset=utf-8'], 'Méthode non autorisée.');
        }
        if (!HostGuard::allows($request->header('Host'), $this->allowedHosts)) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Hôte non autorisé.');
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
        // Version de protocole : refusée si présente et non supportée; `initialize` négocie par `protocolVersion`.
        $version = $request->header('MCP-Protocol-Version');
        if ($version !== null && $message->method !== 'initialize' && !in_array($version, self::SUPPORTED_VERSIONS, true)) {
            return $this->rpcError($id, -32600, 'Version de protocole non supportée.', 400);
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
            ], ...$this->tools->definitions()]]),
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
        if (!is_string($name) || ($name !== 'ping' && !$this->tools->has($name))) {
            return $this->rpcError($id, -32602, 'Outil inconnu.');
        }
        $arguments = property_exists($params, 'arguments') ? $params->arguments : new \stdClass();
        if (!$arguments instanceof \stdClass) {
            return $this->rpcError($id, -32602, 'Arguments invalides : un objet JSON est attendu.');
        }
        if ($name === 'ping') {
            if ((array) $arguments !== []) {
                return $this->rpcError($id, -32602, 'Arguments invalides : ping n\'en accepte aucun.');
            }

            return $this->toolResult($id, $this->diagnostic->ping(), false);
        }
        $args = JsonObject::toArray($arguments);
        try {
            return $this->toolResult($id, $this->tools->call($name, is_array($args) ? $args : []), false);
        } catch (DomainError $e) {
            if ($e instanceof InvalidToolCall || DomainStatus::of($e) !== null) {
                return $this->toolResult($id, ErrorBody::fromDomain($e), true);
            }
            error_log('mcp: code de domaine sans statut : ' . $e->code());

            return $this->rpcError($id, -32603, 'Erreur interne.');
        } catch (DatabaseUnavailable $e) {
            error_log('mcp: base injoignable : ' . ($e->getPrevious()?->getMessage() ?? $e->getMessage()));

            return $this->toolResult($id, ErrorBody::of('service_unavailable', 'Service indisponible.'), true);
        }
    }

    /** Le texte est le JSON compact de la valeur, avec les mêmes options que `Response::json` (comme REST). */
    private function toolResult(int|string $id, mixed $value, bool $isError): Response
    {
        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]],
            'isError' => $isError,
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
