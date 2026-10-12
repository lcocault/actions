<?php

declare(strict_types=1);

namespace Actions\Tests\Http;

final class McpTest extends HttpTestCase
{
    private function rpc(string $method, ?array $params = null, int|string $id = 1): array
    {
        $m = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $m['params'] = $params === [] ? new \stdClass() : $params;
        }

        return $this->decode($this->mcp($m));
    }

    public function testInitializeEchoesSupportedVersion(): void
    {
        $r = $this->rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]);
        self::assertSame(1, $r['id']);
        self::assertSame('2025-03-26', $r['result']['protocolVersion']);
        self::assertSame('actions', $r['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $r['result']['capabilities']);
    }

    public function testInitializeFallsBackToLatest(): void
    {
        self::assertSame('2025-06-18', $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])['result']['protocolVersion']);
    }

    public function testInitializeRequiresProtocolVersion(): void
    {
        self::assertSame(-32602, $this->rpc('initialize', [])['error']['code']);
    }

    public function testInitializedNotificationReturns202WithoutBody(): void
    {
        $r = $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertSame(202, $r->status);
        self::assertSame('', $r->body);
    }

    public function testToolsListExposesNineToolsInOrder(): void
    {
        $tools = $this->rpc('tools/list')['result']['tools'];
        self::assertSame(
            ['ping', 'create_task', 'get_task', 'list_tasks', 'update_task', 'delete_task', 'add_required_action', 'remove_required_action', 'change_task_status'],
            array_column($tools, 'name'),
        );
        foreach ($tools as $tool) {
            self::assertNotSame('', trim($tool['description']), $tool['name']);
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            self::assertFalse($tool['inputSchema']['additionalProperties'], $tool['name']);
        }
    }

    public function testPingReturnsFixedResult(): void
    {
        foreach ([['name' => 'ping'], ['name' => 'ping', 'arguments' => new \stdClass()]] as $params) {
            $r = $this->rpc('tools/call', $params, 'abc');
            self::assertSame('abc', $r['id']);
            self::assertFalse($r['result']['isError']);
            self::assertSame('text', $r['result']['content'][0]['type']);
            self::assertSame(['status' => 'ok', 'service' => 'actions'], json_decode($r['result']['content'][0]['text'], true));
        }
    }

    public function testInvalidToolCallsReturnInvalidParams(): void
    {
        foreach ([['name' => 'autre'], ['name' => 'ping', 'arguments' => ['x' => 1]], ['name' => 'ping', 'arguments' => [1]], []] as $params) {
            self::assertSame(-32602, $this->rpc('tools/call', $params)['error']['code'], json_encode($params));
        }
    }

    public function testUnknownMethod(): void
    {
        self::assertSame(-32601, $this->rpc('resources/list')['error']['code']);
    }

    public function testInvalidJson(): void
    {
        $r = $this->mcp('{pas du json');
        self::assertSame(400, $r->status);
        $d = $this->decode($r);
        self::assertSame(-32700, $d['error']['code']);
        self::assertNull($d['id']);
    }

    public function testBatchIsRejected(): void
    {
        foreach ([[['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']], '[]'] as $batch) {
            $r = $this->mcp($batch);
            self::assertSame(400, $r->status);
            self::assertSame(-32600, $this->decode($r)['error']['code']);
        }
    }

    public function testNonConformingMessages(): void
    {
        foreach (['{}', '"x"', '{"jsonrpc":"1.0","id":1,"method":"x"}', '{"jsonrpc":"2.0","id":1}', '{"jsonrpc":"2.0","id":null,"method":"tools/list"}', '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[1]}'] as $body) {
            $r = $this->mcp($body);
            self::assertSame(400, $r->status, $body);
            self::assertSame(-32600, $this->decode($r)['error']['code'], $body);
        }
    }

    public function testWrongContentTypeReturns415(): void
    {
        $r = $this->mcp('{}', ['Content-Type' => 'text/plain']);
        self::assertSame(415, $r->status);
        self::assertSame(-32600, $this->decode($r)['error']['code']);
        self::assertSame(415, $this->mcp('{}', ['Content-Type' => null])->status);
    }

    public function testContentTypeWithCharsetIsAccepted(): void
    {
        $r = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Content-Type' => 'application/json; charset=utf-8']);
        self::assertSame(200, $r->status);
    }

    public function testGetAndDeleteReturn405(): void
    {
        foreach (['GET', 'DELETE'] as $method) {
            $r = $this->call($method, '/mcp');
            self::assertSame(405, $r->status);
            self::assertSame('POST', $r->headers['Allow']);
        }
    }

    public function testOriginIsChecked(): void
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        $h = ['Content-Type' => 'application/json', 'Origin' => 'http://evil.example'];
        self::assertSame(403, $this->mcp($msg, $h)->status);
        self::assertSame(403, $this->mcp($msg, $h, ['http://ok.example'])->status);
        $h['Origin'] = 'http://ok.example';
        self::assertSame(200, $this->mcp($msg, $h, ['http://ok.example'])->status);
        self::assertSame(200, $this->mcp($msg)->status, 'Sans en-tête Origin la requête est acceptée');
    }

    public function testMcpDoesNotNeedDatabase(): void
    {
        self::assertSame(200, $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->status);
    }
}
