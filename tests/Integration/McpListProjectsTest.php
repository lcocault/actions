<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;

/** Outil MCP `list_projects` (lecture seule) : parité d'octets avec `GET /api/v1/projects`. */
final class McpListProjectsTest extends DbTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->kernel = Bootstrap::kernel($this->config);
    }

    /** @return array<string,mixed> */
    private function rpc(string $method, string $paramsJson): array
    {
        $body = '{"jsonrpc":"2.0","id":5,"method":' . json_encode($method) . ',"params":' . $paramsJson . '}';
        $r = $this->kernel->handle(new Request('POST', '/mcp', ['host' => '127.0.0.1:8080', 'content-type' => 'application/json'], $body));
        self::assertSame(200, $r->status, $r->body);

        return json_decode($r->body, true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function call(string $name, string $argumentsJson = '{}'): array
    {
        return $this->rpc('tools/call', '{"name":' . json_encode($name) . ',"arguments":' . $argumentsJson . '}');
    }

    public function testToolIsListedReadOnlyWithStrictEmptySchema(): void
    {
        $tools = array_column($this->rpc('tools/list', '{}')['result']['tools'], null, 'name');
        self::assertArrayHasKey('list_projects', $tools);
        $tool = $tools['list_projects'];
        self::assertSame(['readOnlyHint' => true], $tool['annotations']);
        self::assertSame('object', $tool['inputSchema']['type']);
        self::assertSame([], $tool['inputSchema']['properties']);
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        self::assertArrayNotHasKey('required', $tool['inputSchema']);
        self::assertSame(['ping', 'create_task', 'get_task', 'list_tasks', 'update_task', 'delete_task', 'add_required_action', 'remove_required_action', 'change_task_status', 'list_projects'], array_keys($tools));
        foreach (['create_project', 'update_project', 'delete_project'] as $write) {
            self::assertArrayNotHasKey($write, $tools);
        }
    }

    public function testResultIsByteIdenticalToRestList(): void
    {
        Bootstrap::taskService($this->config)->create(['title' => 'T', 'project' => 'OPF']);
        $rest = $this->kernel->handle(new Request('GET', '/api/v1/projects'));
        $rpc = $this->call('list_projects');
        self::assertFalse($rpc['result']['isError']);
        self::assertSame($rest->body, $rpc['result']['content'][0]['text']);
        $list = json_decode($rest->body, true);
        self::assertCount(6, $list);
        self::assertSame(['id', 'name', 'category', 'task_count'], array_keys($list[0]));
    }

    public function testReflectsCreationAndRenameDoneByRest(): void
    {
        $this->kernel->handle(new Request('POST', '/api/v1/projects', [], '{"name":"Nouveau","category":"Pro"}'));
        $id = $this->projectId('OPF');
        $this->kernel->handle(new Request('PATCH', '/api/v1/projects/' . $id, [], '{"name":"Renommé"}'));
        $names = array_column(json_decode($this->call('list_projects')['result']['content'][0]['text'], true), 'name');
        self::assertContains('Nouveau', $names);
        self::assertContains('Renommé', $names);
        self::assertNotContains('OPF', $names);
    }

    public function testEmptyListIsAnEmptyArray(): void
    {
        $this->pdo->exec('DELETE FROM projects');
        self::assertSame('[]', $this->call('list_projects')['result']['content'][0]['text']);
    }

    public function testUnknownArgumentIsAToolErrorFieldUnknown(): void
    {
        $rpc = $this->call('list_projects', '{"nom":"x"}');
        self::assertArrayNotHasKey('error', $rpc);
        self::assertTrue($rpc['result']['isError']);
        $error = json_decode($rpc['result']['content'][0]['text'], true)['error'];
        self::assertSame('field_unknown', $error['code']);
        self::assertSame('nom', $error['details']['field']);
    }

    public function testNonObjectArgumentsAreAProtocolError(): void
    {
        $rpc = $this->rpc('tools/call', '{"name":"list_projects","arguments":[1]}');
        self::assertSame(-32602, $rpc['error']['code']);
    }
}
