<?php

declare(strict_types=1);

namespace Actions\Tests\Http;

use Actions\Application\TaskStatus;
use Actions\Application\TaskValidator;
use Actions\Mcp\McpAdapter;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Contrat MCP des outils sans accès à la base : schémas, forme des appels (argument_invalid, confirmation_required,
 * field_unknown des outils à arguments fixes), erreurs de protocole et gardes HTTP. Aucun de ces cas n'atteint le cas d'usage
 * (la base de ces tests est injoignable : un appel qui y arriverait donnerait service_unavailable).
 */
final class McpToolsTest extends HttpTestCase
{
    /** @return array<string,mixed> */
    private function tools(): array
    {
        $r = $this->decode($this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        $out = [];
        foreach ($r['result']['tools'] as $tool) {
            $out[$tool['name']] = $tool;
        }

        return $out;
    }

    /** @param string $argumentsJson JSON brut de `arguments`, ou null pour l'omettre */
    private function rawCall(string $name, ?string $argumentsJson): \Actions\Http\Response
    {
        $args = $argumentsJson === null ? '' : ',"arguments":' . $argumentsJson;

        return $this->mcp('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":' . json_encode($name) . $args . '}}');
    }

    /** @return array{0:array<string,mixed>,1:string} erreur d'outil décodée et son texte brut */
    private function toolError(\Actions\Http\Response $r): array
    {
        self::assertSame(200, $r->status);
        $d = $this->decode($r);
        self::assertArrayNotHasKey('error', $d, $r->body);
        self::assertTrue($d['result']['isError'], $r->body);
        self::assertCount(1, $d['result']['content']);
        self::assertSame('text', $d['result']['content'][0]['type']);
        $text = $d['result']['content'][0]['text'];

        return [json_decode($text, true, 32, JSON_THROW_ON_ERROR)['error'], $text];
    }

    public function testRequiredArgumentsAndStrictSchemas(): void
    {
        $expected = [
            'ping' => [],
            'create_task' => ['title', 'category'],
            'get_task' => ['id'],
            'list_tasks' => [],
            'update_task' => ['id'],
            'delete_task' => ['id', 'confirm'],
            'add_required_action' => ['id', 'required_task_id'],
            'remove_required_action' => ['id', 'required_task_id'],
            'change_task_status' => ['id', 'status'],
        ];
        $tools = $this->tools();
        foreach ($expected as $name => $required) {
            $schema = $tools[$name]['inputSchema'];
            self::assertSame($required, $schema['required'] ?? [], $name);
            self::assertFalse($schema['additionalProperties'], $name);
            foreach ($required as $field) {
                self::assertArrayHasKey($field, $schema['properties'], "$name.$field");
            }
        }
        foreach (['get_task', 'update_task', 'delete_task', 'add_required_action', 'remove_required_action', 'change_task_status'] as $name) {
            self::assertSame(['type' => 'integer', 'minimum' => 1], array_intersect_key($tools[$name]['inputSchema']['properties']['id'], ['type' => 1, 'minimum' => 1]), $name);
        }
        self::assertSame(['type' => 'boolean', 'const' => true], array_intersect_key($tools['delete_task']['inputSchema']['properties']['confirm'], ['type' => 1, 'const' => 1]));
    }

    public function testSchemaEnumerationsAreTheDomainOnes(): void
    {
        $tools = $this->tools();
        self::assertSame(TaskStatus::codes(), $tools['change_task_status']['inputSchema']['properties']['status']['enum']);
        self::assertSame(TaskStatus::codes(), $tools['list_tasks']['inputSchema']['properties']['status']['enum']);
        self::assertSame(TaskValidator::CATEGORIES, $tools['list_tasks']['inputSchema']['properties']['category']['enum']);
        foreach (['create_task', 'update_task'] as $name) {
            $props = $tools[$name]['inputSchema']['properties'];
            self::assertSame(TaskValidator::CATEGORIES, $props['category']['enum'], $name);
            self::assertSame(TaskValidator::UNITS, $props['recurrence']['properties']['unit']['enum'], $name);
            self::assertSame(['interval', 'unit'], $props['recurrence']['required'], $name);
            self::assertFalse($props['recurrence']['additionalProperties'], $name);
            self::assertSame(255, $props['title']['maxLength'], $name);
            self::assertArrayNotHasKey('status', $props, $name);
            self::assertArrayNotHasKey('id', $tools['create_task']['inputSchema']['properties']);
        }
        self::assertStringContainsString('change_task_status', $tools['update_task']['description']);
    }

    public function testAnnotations(): void
    {
        $tools = $this->tools();
        self::assertSame(['readOnlyHint' => true], $tools['get_task']['annotations']);
        self::assertSame(['readOnlyHint' => true], $tools['list_tasks']['annotations']);
        self::assertSame(['destructiveHint' => true], $tools['delete_task']['annotations']);
        self::assertSame(['idempotentHint' => true], $tools['add_required_action']['annotations']);
        self::assertSame(['idempotentHint' => true], $tools['remove_required_action']['annotations']);
        self::assertArrayNotHasKey('annotations', $tools['create_task']);
        self::assertStringContainsString('accord', $tools['delete_task']['description']);
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function badIdProvider(): array
    {
        return [
            'absent' => ['{}', null],
            'zéro' => ['{"id":0}', 'id'],
            'négatif' => ['{"id":-1}', 'id'],
            'chaîne' => ['{"id":"5"}', 'id'],
            'décimal' => ['{"id":1.0}', 'id'],
            'null' => ['{"id":null}', 'id'],
            'booléen' => ['{"id":true}', 'id'],
            'liste' => ['{"id":[1]}', 'id'],
            'hors entier PHP' => ['{"id":99999999999999999999}', 'id'],
        ];
    }

    #[DataProvider('badIdProvider')]
    public function testBadIdGivesArgumentInvalid(string $arguments, ?string $unused): void
    {
        foreach (['get_task', 'update_task', 'add_required_action', 'remove_required_action', 'change_task_status'] as $name) {
            [$e] = $this->toolError($this->rawCall($name, $arguments));
            self::assertSame('argument_invalid', $e['code'], "$name $arguments");
            self::assertSame('id', $e['details']['field']);
            self::assertSame('L\'identifiant doit être un entier positif.', $e['message']);
        }
        [$e] = $this->toolError($this->rawCall('delete_task', substr($arguments, 0, -1) . (strlen($arguments) > 2 ? ',' : '') . '"confirm":true}'));
        self::assertSame('argument_invalid', $e['code']);
    }

    public function testBadRequiredTaskIdGivesArgumentInvalid(): void
    {
        foreach (['add_required_action', 'remove_required_action'] as $name) {
            foreach (['{"id":1}', '{"id":1,"required_task_id":0}', '{"id":1,"required_task_id":"2"}', '{"id":1,"required_task_id":2.0}', '{"id":1,"required_task_id":99999999999999999999}'] as $args) {
                [$e] = $this->toolError($this->rawCall($name, $args));
                self::assertSame('argument_invalid', $e['code'], "$name $args");
                self::assertSame('required_task_id', $e['details']['field']);
            }
        }
    }

    public function testUnknownKeysOnFixedArgumentTools(): void
    {
        $cases = [
            'get_task' => '{"id":1,"foo":1}',
            'delete_task' => '{"id":1,"confirm":true,"foo":1}',
            'add_required_action' => '{"id":1,"required_task_id":2,"foo":1}',
            'remove_required_action' => '{"id":1,"required_task_id":2,"foo":1}',
            'change_task_status' => '{"id":1,"status":"done","foo":1}',
        ];
        foreach ($cases as $name => $args) {
            [$e, $text] = $this->toolError($this->rawCall($name, $args));
            self::assertSame(['code' => 'field_unknown', 'message' => 'Champ inconnu.', 'details' => ['field' => 'foo']], $e, $name);
            self::assertSame('{"error":{"code":"field_unknown","message":"Champ inconnu.","details":{"field":"foo"}}}', $text);
        }
        // Les clés inconnues sont contrôlées avant la forme des identifiants.
        [$e] = $this->toolError($this->rawCall('get_task', '{"id":"x","foo":1}'));
        self::assertSame('field_unknown', $e['code']);
    }

    /** @return array<string,array{0:string}> */
    public static function badConfirmProvider(): array
    {
        return [
            'absent' => ['{"id":1}'],
            'false' => ['{"id":1,"confirm":false}'],
            'chaîne' => ['{"id":1,"confirm":"true"}'],
            'nombre' => ['{"id":1,"confirm":1}'],
            'null' => ['{"id":1,"confirm":null}'],
            'id inconnu' => ['{"id":999999,"confirm":false}'],
        ];
    }

    #[DataProvider('badConfirmProvider')]
    public function testDeleteWithoutStrictConfirmIsRefusedBeforeAnyDatabaseAccess(string $arguments): void
    {
        // Base injoignable : si le cas d'usage était appelé, on obtiendrait service_unavailable.
        [$e] = $this->toolError($this->rawCall('delete_task', $arguments));
        self::assertSame('confirmation_required', $e['code']);
        self::assertSame(['field' => 'confirm'], $e['details']);
        self::assertStringContainsString('confirm: true', $e['message']);
    }

    public function testProtocolErrorsRemainJsonRpcErrors(): void
    {
        $cases = [
            'arguments liste' => ['get_task', '[1]'],
            'arguments chaîne' => ['get_task', '"x"'],
            'arguments null' => ['get_task', 'null'],
            'arguments nombre' => ['list_tasks', '1'],
            'outil inconnu' => ['inconnu', '{}'],
        ];
        foreach ($cases as $label => [$name, $args]) {
            $d = $this->decode($this->rawCall($name, $args));
            self::assertSame(-32602, $d['error']['code'], $label);
            self::assertArrayNotHasKey('result', $d, $label);
        }
        $d = $this->decode($this->mcp('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"arguments":{}}}'));
        self::assertSame(-32602, $d['error']['code']);
    }

    public function testPingStillRefusesArguments(): void
    {
        self::assertSame(-32602, $this->decode($this->rawCall('ping', '{"x":1}'))['error']['code']);
        self::assertFalse($this->decode($this->rawCall('ping', null))['result']['isError']);
    }

    public function testUnreachableDatabaseGivesServiceUnavailableToolError(): void
    {
        foreach (['get_task' => '{"id":1}', 'list_tasks' => null, 'create_task' => '{"title":"T","category":"Pro"}', 'delete_task' => '{"id":1,"confirm":true}'] as $name => $args) {
            [$e, $text] = $this->toolError($this->rawCall($name, $args));
            self::assertSame(['code' => 'service_unavailable', 'message' => 'Service indisponible.'], $e, $name);
            foreach (['127.0.0.1', 'secret_db', 'secret_user', 'secret_pwd', 'SQLSTATE'] as $leak) {
                self::assertStringNotContainsString($leak, $text);
            }
        }
    }

    public function testUnexpectedPdoExceptionGivesInternalErrorWithoutLeak(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $e = new \PDOException('SECRET_MESSAGE relation "tasks" SELECT secret_col');
        $e->errorInfo = ['XX000', 7, 'SECRET_DRIVER_MESSAGE'];
        $pdo->method('prepare')->willThrowException($e);
        $pdo->method('beginTransaction')->willThrowException($e);
        $service = new \Actions\Application\TaskService(new \Actions\Application\TaskRepository(static fn (): \PDO => $pdo));
        $adapter = new McpAdapter(new \Actions\Application\Diagnostic(static fn (): \PDO => $pdo), $service, [], ['127.0.0.1']);
        foreach (['get_task' => '{"id":1}', 'list_tasks' => '{}', 'create_task' => '{"title":"T","category":"Pro"}', 'update_task' => '{"id":1,"title":"x"}'] as $name => $args) {
            $body = '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"' . $name . '","arguments":' . $args . '}}';
            $r = $adapter->handle(new \Actions\Http\Request('POST', '/mcp', ['host' => '127.0.0.1:8080', 'content-type' => 'application/json'], $body));
            $d = $this->decode($r);
            self::assertSame(-32603, $d['error']['code'], $name);
            self::assertSame('Erreur interne.', $d['error']['message']);
            foreach (['XX000', 'SECRET', 'SELECT', 'PDO'] as $leak) {
                self::assertStringNotContainsString($leak, $r->body);
            }
        }
    }

    // --- Gardes HTTP : méthode (405) -> Host (403) -> Origin (403) ---

    public function testHostGuard(): void
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        foreach (['127.0.0.1:8080', '127.0.0.1', 'localhost:9000', 'LOCALHOST', '[::1]:8080'] as $host) {
            self::assertSame(200, $this->mcp($msg, ['Host' => $host])->status, $host);
        }
        foreach (['attaquant.example', 'attaquant.example:8080', '127.0.0.1.attaquant.example', '127.0.0.1:abc', '127.0.0.1:123456', '[::1', '', 'a b'] as $host) {
            $r = $this->mcp($msg, ['Host' => $host]);
            self::assertSame(403, $r->status, $host);
            self::assertStringNotContainsString('attaquant', $r->body);
        }
        self::assertSame(403, $this->mcp($msg, ['Host' => null])->status, 'Host absent');
    }

    public function testHostGuardRunsBeforeAnyToolAndMethodGuardRunsFirst(): void
    {
        $r = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'delete_task', 'arguments' => ['id' => 1, 'confirm' => true]]], ['Host' => 'evil.example']);
        self::assertSame(403, $r->status);
        self::assertStringNotContainsString('service_unavailable', $r->body);
        foreach (['GET', 'DELETE'] as $method) {
            self::assertSame(405, $this->call($method, '/mcp', ['host' => 'evil.example'])->status);
            self::assertSame(405, $this->call($method, '/mcp')->status, 'Sans Host : 405');
        }
    }

    public function testHostIsCheckedBeforeOrigin(): void
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        $r = $this->mcp($msg, ['Host' => 'evil.example', 'Origin' => 'http://ok.example'], ['http://ok.example']);
        self::assertSame(403, $r->status);
        self::assertSame('Hôte non autorisé.', $r->body);
        $r = $this->mcp($msg, ['Origin' => 'http://evil.example'], ['http://ok.example']);
        self::assertSame(403, $r->status);
        self::assertSame('Origine non autorisée.', $r->body);
    }

    public function testConfiguredAllowedHostsReplaceTheDefault(): void
    {
        $config = new \Actions\Infrastructure\Config('test', '127.0.0.1', 1, 'n', 'u', 'p', [], ['actions.local'], ['actions.local']);
        $kernel = \Actions\Bootstrap::kernel($config);
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
        $send = static fn (string $host): int => $kernel->handle(new \Actions\Http\Request('POST', '/mcp', ['host' => $host, 'content-type' => 'application/json'], $body))->status;
        self::assertSame(200, $send('actions.local:8080'));
        self::assertSame(403, $send('127.0.0.1:8080'));
    }

    // --- En-tête MCP-Protocol-Version ---

    public function testUnsupportedProtocolVersionHeaderGives400(): void
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        foreach (['2026-07-28', '1999-01-01', 'abc', ''] as $version) {
            $r = $this->mcp($msg, ['MCP-Protocol-Version' => $version]);
            self::assertSame(400, $r->status, $version);
            $d = $this->decode($r);
            self::assertSame(-32600, $d['error']['code']);
            self::assertSame('Version de protocole non supportée.', $d['error']['message']);
            self::assertSame(1, $d['id']);
        }
    }

    public function testSupportedOrAbsentProtocolVersionIsAccepted(): void
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        self::assertSame(200, $this->mcp($msg)->status);
        self::assertSame(200, $this->mcp($msg, ['MCP-Protocol-Version' => '2025-06-18'])->status);
        self::assertSame(200, $this->mcp($msg, ['MCP-Protocol-Version' => '2025-03-26'])->status);
    }

    public function testInitializeIsExemptFromProtocolVersionHeader(): void
    {
        $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2026-07-28']];
        $r = $this->mcp($init, ['MCP-Protocol-Version' => '2026-07-28']);
        self::assertSame(200, $r->status);
        self::assertSame('2025-06-18', $this->decode($r)['result']['protocolVersion']);
    }

    public function testServerInfoVersionIsBumped(): void
    {
        $r = $this->decode($this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));
        self::assertSame('0.2.0', $r['result']['serverInfo']['version']);
    }
}
