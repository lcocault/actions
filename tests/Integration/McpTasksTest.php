<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Bootstrap;
use Actions\Http\Kernel;
use Actions\Http\Request;
use Actions\Http\Response;
use Actions\Infrastructure\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Outils MCP des tâches : Kernel -> McpAdapter -> TaskTools -> TaskService -> PostgreSQL réel.
 * Parité : pour chaque règle refusée, le texte de l'erreur MCP est identique, octet pour octet, au corps REST de la même opération.
 */
final class McpTasksTest extends DbTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
        $this->kernel = Bootstrap::kernel($this->config);
    }

    // --- Aides ---

    /** @param ?array<string,mixed> $query */
    private function rest(string $method, string $path, mixed $body = null, array $query = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, [], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), $query));
    }

    /** @return array<string,mixed> réponse JSON-RPC complète */
    private function rpc(string $name, ?string $argumentsJson): array
    {
        $args = $argumentsJson === null ? '' : ',"arguments":' . $argumentsJson;
        $body = '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":' . json_encode($name) . $args . '}}';
        $r = $this->kernel->handle(new Request('POST', '/mcp', ['host' => '127.0.0.1:8080', 'content-type' => 'application/json'], $body));
        self::assertSame(200, $r->status, $r->body);
        self::assertSame('application/json', $r->headers['Content-Type']);

        return json_decode($r->body, true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $arguments */
    private function args(array $arguments): string
    {
        return $arguments === [] ? '{}' : json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** Texte brut du résultat d'outil; vérifie la forme de l'enveloppe. */
    private function text(array $rpc, bool $isError): string
    {
        self::assertArrayNotHasKey('error', $rpc, json_encode($rpc));
        self::assertSame(5, $rpc['id']);
        self::assertSame($isError, $rpc['result']['isError'], json_encode($rpc));
        self::assertCount(1, $rpc['result']['content']);
        self::assertSame('text', $rpc['result']['content'][0]['type']);
        self::assertArrayNotHasKey('structuredContent', $rpc['result']);

        return $rpc['result']['content'][0]['text'];
    }

    /** @param array<string,mixed> $arguments @return mixed */
    private function ok(string $name, array $arguments = []): mixed
    {
        return json_decode($this->text($this->rpc($name, $this->args($arguments)), false), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $arguments @return array{0:array<string,mixed>,1:string} erreur décodée, texte brut */
    private function err(string $name, array $arguments, ?string $code = null, ?string $field = null): array
    {
        $text = $this->text($this->rpc($name, $this->args($arguments)), true);
        $error = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['error'], array_keys($error));
        self::assertIsString($error['error']['message']);
        if ($code !== null) {
            self::assertSame($code, $error['error']['code'], $text);
        }
        if ($field !== null) {
            self::assertSame($field, $error['error']['details']['field'], $text);
        }

        return [$error['error'], $text];
    }

    /** @return array<string,mixed> tâche créée par REST (aide de préparation) */
    private function make(array $data): array
    {
        $r = $this->rest('POST', '/api/v1/tasks', $data);
        self::assertSame(201, $r->status, $r->body);

        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        return json_decode($this->rest('GET', "/api/v1/tasks/$id")->body, true, 32, JSON_THROW_ON_ERROR);
    }

    private function backdate(int $id): void
    {
        $this->pdo->exec("UPDATE tasks SET updated_at = now() - interval '1 day' WHERE id = $id");
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn();
    }

    private function links(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM task_dependencies')->fetchColumn();
    }

    /** Refus MCP identique au refus REST : même texte, octet pour octet. Retourne l'erreur décodée. */
    private function assertParity(string $text, Response $rest, int $status, string $code): array
    {
        self::assertSame($status, $rest->status, $rest->body);
        self::assertSame($rest->body, $text, 'Le texte MCP doit être le corps REST');
        $error = json_decode($text, true)['error'];
        self::assertSame($code, $error['code']);

        return $error;
    }

    // --- Succès ---

    public function testCreateMinimalAndFullMatchRestShape(): void
    {
        $min = $this->ok('create_task', ['title' => 'Minimale', 'project' => 'Divers Pro']);
        self::assertIsInt($min['id']);
        self::assertSame('Minimale', $min['title']);
        foreach (['due_date', 'recurrence', 'estimated_minutes', 'description', 'conclusions', 'completed_at'] as $f) {
            self::assertNull($min[$f], $f);
        }
        self::assertSame('Divers Pro', $min['project']);
        self::assertSame('Pro', $min['category'], 'catégorie dérivée du projet');
        self::assertSame([], $min['required_actions']);
        self::assertSame('todo', $min['status']);
        self::assertSame($this->row($min['id']), $min, 'Même Task que REST');

        $full = $this->ok('create_task', [
            'title' => 'Complète é/ü', 'project' => 'Divers Perso', 'due_date' => '2026-11-30',
            'recurrence' => ['interval' => 2, 'unit' => 'week'], 'estimated_minutes' => 45, 'description' => "Ligne 1\nLigne 2",
        ]);
        self::assertSame('2026-11-30', $full['due_date']);
        self::assertSame(['interval' => 2, 'unit' => 'week'], $full['recurrence']);
        self::assertSame(45, $full['estimated_minutes']);
        self::assertSame($this->row($full['id']), $full);
    }

    public function testCreateWithExplicitNullOptionalsIsAccepted(): void
    {
        $t = $this->ok('create_task', ['title' => 'T', 'project' => 'Divers Pro', 'due_date' => null, 'recurrence' => null, 'estimated_minutes' => null, 'description' => null]);
        self::assertNull($t['due_date']);
    }

    public function testResultTextIsCompactUnescapedJsonLikeRest(): void
    {
        $this->make(['title' => 'a/b é', 'project' => 'Divers Pro']);
        $text = $this->text($this->rpc('list_tasks', null), false);
        self::assertSame($this->rest('GET', '/api/v1/tasks')->body, $text);
        self::assertStringContainsString('a/b é', $text);
    }

    public function testGetTask(): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        self::assertSame($t, $this->ok('get_task', ['id' => $t['id']]));
    }

    public function testListEmptyIsEmptyArrayAndOrderIsRestOrder(): void
    {
        self::assertSame('[]', $this->text($this->rpc('list_tasks', null), false));
        self::assertSame('[]', $this->text($this->rpc('list_tasks', '{}'), false));
        $c = $this->make(['title' => 'C sans date', 'project' => 'Divers Pro']);
        $b = $this->make(['title' => 'B tard', 'project' => 'Divers Pro', 'due_date' => '2026-12-01']);
        $a = $this->make(['title' => 'A tôt', 'project' => 'Divers Perso', 'due_date' => '2026-10-01']);
        $d = $this->make(['title' => 'D sans date', 'project' => 'Divers Perso']);
        $list = $this->ok('list_tasks');
        self::assertSame([$a['id'], $b['id'], $c['id'], $d['id']], array_column($list, 'id'));
        self::assertSame(json_decode($this->rest('GET', '/api/v1/tasks')->body, true), $list);
    }

    public function testListFiltersAloneAndCombined(): void
    {
        $a = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $b = $this->make(['title' => 'B', 'project' => 'Divers Perso']);
        $c = $this->make(['title' => 'C', 'project' => 'Divers Pro']);
        $this->ok('change_task_status', ['id' => $c['id'], 'status' => 'in_progress']);
        self::assertSame([$a['id'], $c['id']], array_column($this->ok('list_tasks', ['category' => 'Pro']), 'id'));
        self::assertSame([$c['id']], array_column($this->ok('list_tasks', ['status' => 'in_progress']), 'id'));
        self::assertSame([$a['id'], $b['id']], array_column($this->ok('list_tasks', ['status' => 'todo']), 'id'));
        self::assertSame([$c['id']], array_column($this->ok('list_tasks', ['status' => 'in_progress', 'category' => 'Pro']), 'id'));
        self::assertSame([], $this->ok('list_tasks', ['status' => 'done', 'category' => 'Perso']));
    }

    public function testUpdateChangesPresentFieldsNullClearsAbsentUnchanged(): void
    {
        $t = $this->make(['title' => 'Avant', 'project' => 'Divers Pro', 'due_date' => '2026-11-01', 'estimated_minutes' => 30, 'description' => 'D']);
        $this->backdate($t['id']);
        $stamp = $this->row($t['id'])['updated_at'];
        $u = $this->ok('update_task', ['id' => $t['id'], 'title' => 'Après', 'estimated_minutes' => null]);
        self::assertSame('Après', $u['title']);
        self::assertNull($u['estimated_minutes']);
        self::assertSame('2026-11-01', $u['due_date']);
        self::assertSame('D', $u['description']);
        self::assertSame($this->row($t['id']), $u);
        self::assertNotSame($stamp, $u['updated_at']);
    }

    public function testUpdateWithIdOnlyAndIdenticalValuesIsANoOp(): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $this->backdate($t['id']);
        $before = $this->row($t['id']);
        self::assertSame($before, $this->ok('update_task', ['id' => $t['id']]));
        self::assertSame($before, $this->ok('update_task', ['id' => $t['id'], 'title' => 'A', 'project' => 'Divers Pro']));
        self::assertSame($before['updated_at'], $this->row($t['id'])['updated_at']);
    }

    public function testUpdateKeepsRequiredActions(): void
    {
        $a = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $b = $this->make(['title' => 'B', 'project' => 'Divers Pro']);
        $this->ok('add_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        $u = $this->ok('update_task', ['id' => $a['id'], 'title' => 'A2']);
        self::assertSame([['id' => $b['id'], 'title' => 'B']], $u['required_actions']);
    }

    public function testAddAndRemoveRequiredActionAreIdempotent(): void
    {
        $a = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $b = $this->make(['title' => 'B', 'project' => 'Divers Pro']);
        $this->backdate($a['id']);
        $withLink = $this->ok('add_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        self::assertSame([['id' => $b['id'], 'title' => 'B']], $withLink['required_actions']);
        self::assertSame($this->row($a['id']), $withLink);
        $this->backdate($a['id']);
        $stamp = $this->row($a['id'])['updated_at'];
        $again = $this->ok('add_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        self::assertCount(1, $again['required_actions']);
        self::assertSame($stamp, $again['updated_at'], 'Second ajout : updated_at inchangé');
        self::assertSame(1, $this->links());

        $removed = $this->ok('remove_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        self::assertSame([], $removed['required_actions']);
        $this->backdate($a['id']);
        $stamp = $this->row($a['id'])['updated_at'];
        $twice = $this->ok('remove_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        self::assertSame($stamp, $twice['updated_at'], 'Second retrait : updated_at inchangé');
        self::assertSame(0, $this->links());
    }

    public function testChangeStatusTransitionsAndShape(): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $r = $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'in_progress']);
        self::assertSame('in_progress', $r['status']);
        self::assertArrayHasKey('next_occurrence', $r);
        self::assertNull($r['next_occurrence']);
        self::assertSame('done', $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'done'])['status']);
        $done = $this->row($t['id']);
        self::assertNotNull($done['completed_at']);
        $back = $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'todo']);
        self::assertSame('todo', $back['status']);
        self::assertNull($back['completed_at']);
        $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'in_progress']);
        self::assertSame('todo', $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'todo'])['status']);
    }

    public function testCompletingRecurringTaskReturnsNextOccurrenceEndOfMonthClamped(): void
    {
        $t = $this->make(['title' => 'Loyer', 'project' => 'Divers Perso', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'in_progress']);
        $r = $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'done']);
        self::assertSame('done', $r['status']);
        self::assertSame('2026-02-28', $r['next_occurrence']['due_date']);
        self::assertSame('todo', $r['next_occurrence']['status']);
        self::assertSame($this->row($r['next_occurrence']['id']), $r['next_occurrence']);
        self::assertSame(2, $this->rows());
    }

    public function testDeleteWithConfirmDeletesAndThenTaskIsUnknown(): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        self::assertSame(['deleted' => true, 'id' => $t['id']], $this->ok('delete_task', ['id' => $t['id'], 'confirm' => true]));
        $this->err('get_task', ['id' => $t['id']], 'task_not_found');
        $this->err('delete_task', ['id' => $t['id'], 'confirm' => true], 'task_not_found');
        self::assertSame(0, $this->rows());
    }

    /** @return array<string,array{0:string}> */
    public static function badConfirmProvider(): array
    {
        return ['absent' => ['{"id":%d}'], 'false' => ['{"id":%d,"confirm":false}'], 'chaîne' => ['{"id":%d,"confirm":"true"}'], 'nombre' => ['{"id":%d,"confirm":1}']];
    }

    #[DataProvider('badConfirmProvider')]
    public function testDeleteWithoutConfirmLeavesTaskIntact(string $template): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $text = $this->text($this->rpc('delete_task', sprintf($template, $t['id'])), true);
        self::assertSame('confirmation_required', json_decode($text, true)['error']['code']);
        self::assertSame(1, $this->rows());
        $unknown = $this->text($this->rpc('delete_task', sprintf($template, 424242)), true);
        self::assertSame('confirmation_required', json_decode($unknown, true)['error']['code'], 'Même pour un id inconnu : aucun accès à la base');
    }

    // --- Parité avec REST ---

    /** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
    public static function invalidCreateProvider(): array
    {
        $ok = ['title' => 'T', 'project' => 'Divers Pro'];

        return [
            'titre blanc' => [['title' => '   ', 'project' => 'Divers Pro'], 'title_blank', 'title'],
            'titre absent' => [['project' => 'Divers Pro'], 'title_blank', 'title'],
            'titre 256' => [['title' => str_repeat('a', 256), 'project' => 'Divers Pro'], 'title_too_long', 'title'],
            'titre nombre' => [['title' => 12, 'project' => 'Divers Pro'], 'field_type_invalid', 'title'],
            'titre null' => [['title' => null, 'project' => 'Divers Pro'], 'field_type_invalid', 'title'],
            'catégorie refusée' => [['title' => 'T', 'project' => 'Divers Pro', 'category' => 'Pro'], 'field_unknown', 'category'],
            'catégorie refusée sans projet' => [['title' => 'T', 'category' => 'Pro'], 'field_unknown', 'category'],
            'projet absent' => [['title' => 'T'], 'project_invalid', 'project'],
            'projet null' => [['title' => 'T', 'project' => null], 'project_invalid', 'project'],
            'projet inconnu' => [['title' => 'T', 'project' => 'Inexistant'], 'project_invalid', 'project'],
            'projet casse' => [['title' => 'T', 'project' => 'divers pro'], 'project_invalid', 'project'],
            'projet vide' => [['title' => 'T', 'project' => ''], 'project_invalid', 'project'],
            'projet nombre' => [['title' => 'T', 'project' => 3], 'field_type_invalid', 'project'],
            'date inexistante' => [$ok + ['due_date' => '2026-02-30'], 'due_date_invalid', 'due_date'],
            'date non chaîne' => [$ok + ['due_date' => 20261012], 'field_type_invalid', 'due_date'],
            'estimation 0' => [$ok + ['estimated_minutes' => 0], 'estimated_minutes_invalid', 'estimated_minutes'],
            'estimation décimale' => [$ok + ['estimated_minutes' => 1.5], 'field_type_invalid', 'estimated_minutes'],
            'description nombre' => [$ok + ['description' => 3], 'field_type_invalid', 'description'],
            'intervalle 0' => [$ok + ['due_date' => '2026-10-12', 'recurrence' => ['interval' => 0, 'unit' => 'day']], 'recurrence_interval_invalid', 'recurrence.interval'],
            'unité inconnue' => [$ok + ['due_date' => '2026-10-12', 'recurrence' => ['interval' => 1, 'unit' => 'hour']], 'recurrence_unit_invalid', 'recurrence.unit'],
            'périodicité incomplète' => [$ok + ['due_date' => '2026-10-12', 'recurrence' => ['interval' => 1]], 'recurrence_incomplete', 'recurrence'],
            'périodicité sans date' => [$ok + ['recurrence' => ['interval' => 1, 'unit' => 'day']], 'recurrence_requires_due_date', 'recurrence'],
            'clé inconnue' => [$ok + ['color' => 'red'], 'field_unknown', 'color'],
            'id fourni' => [$ok + ['id' => 5], 'field_unknown', 'id'],
            'required_actions fourni' => [$ok + ['required_actions' => []], 'field_unknown', 'required_actions'],
            'status fourni' => [$ok + ['status' => 'done'], 'field_unknown', 'status'],
            'created_at fourni' => [$ok + ['created_at' => '2026-01-01T00:00:00Z'], 'field_unknown', 'created_at'],
        ];
    }

    /** @param array<string,mixed> $data */
    #[DataProvider('invalidCreateProvider')]
    public function testCreateRefusalsMatchRestAndCreateNothing(array $data, string $code, string $field): void
    {
        [$error, $text] = $this->err('create_task', $data, $code, $field);
        $this->assertParity($text, $this->rest('POST', '/api/v1/tasks', $data), 422, $code);
        self::assertSame(0, $this->rows());
    }

    /** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
    public static function invalidUpdateProvider(): array
    {
        return [
            'titre null' => [['title' => null], 'field_type_invalid', 'title'],
            'titre blanc' => [['title' => ' '], 'title_blank', 'title'],
            'catégorie refusée' => [['category' => 'Pro'], 'field_unknown', 'category'],
            'projet null' => [['project' => null], 'project_invalid', 'project'],
            'projet inconnu' => [['project' => 'Inexistant'], 'project_invalid', 'project'],
            'projet nombre' => [['project' => 7], 'field_type_invalid', 'project'],
            'date invalide' => [['due_date' => '2026-13-01'], 'due_date_invalid', 'due_date'],
            'estimation négative' => [['estimated_minutes' => -3], 'estimated_minutes_invalid', 'estimated_minutes'],
            'périodicité sans date' => [['recurrence' => ['interval' => 1, 'unit' => 'day']], 'recurrence_requires_due_date', 'recurrence'],
            'required_actions' => [['required_actions' => [1]], 'field_unknown', 'required_actions'],
            'updated_at' => [['updated_at' => 'x'], 'field_unknown', 'updated_at'],
            'inconnue avec valide' => [['title' => 'Nouveau', 'foo' => 1], 'field_unknown', 'foo'],
        ];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidUpdateProvider')]
    public function testUpdateRefusalsMatchRestAndTaskIsUnchanged(array $changes, string $code, string $field): void
    {
        $t = $this->make(['title' => 'Stable', 'project' => 'Divers Pro']);
        $this->backdate($t['id']);
        $before = $this->row($t['id']);
        [, $text] = $this->err('update_task', ['id' => $t['id']] + $changes, $code, $field);
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], $changes), 422, $code);
        self::assertSame($before, $this->row($t['id']));
    }

    public function testUpdateWithStatusIsFieldUnknownNotAStatusChange(): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        [$e] = $this->err('update_task', ['id' => $t['id'], 'status' => 'done'], 'field_unknown', 'status');
        self::assertSame('todo', $this->row($t['id'])['status']);
        $this->err('update_task', ['id' => $t['id'], 'status' => 'in_progress', 'title' => 'x'], 'field_unknown', 'status');
        self::assertSame('A', $this->row($t['id'])['title']);
        self::assertSame('Champ inconnu.', $e['message']);
    }

    public function testClearingDueDateOfRecurringTaskIsRefusedAndTaskUnchanged(): void
    {
        $t = $this->make(['title' => 'P', 'project' => 'Divers Pro', 'due_date' => '2026-10-12', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->backdate($t['id']);
        $before = $this->row($t['id']);
        [, $text] = $this->err('update_task', ['id' => $t['id'], 'due_date' => null], 'recurrence_requires_due_date');
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['due_date' => null]), 422, 'recurrence_requires_due_date');
        self::assertSame($before, $this->row($t['id']));
    }

    /** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
    public static function invalidFilterProvider(): array
    {
        return [
            'état inconnu' => [['status' => 'finie'], 'status_invalid', 'status'],
            'état vide' => [['status' => ''], 'status_invalid', 'status'],
            'état null' => [['status' => null], 'field_type_invalid', 'status'],
            'état nombre' => [['status' => 1], 'field_type_invalid', 'status'],
            'catégorie inconnue' => [['category' => 'Autre'], 'category_invalid', 'category'],
            'catégorie liste' => [['category' => ['Pro']], 'field_type_invalid', 'category'],
            'filtre inconnu' => [['foo' => 'x'], 'field_unknown', 'foo'],
        ];
    }

    /** @param array<string,mixed> $filters */
    #[DataProvider('invalidFilterProvider')]
    public function testListFilterRefusals(array $filters, string $code, string $field): void
    {
        $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $this->err('list_tasks', $filters, $code, $field);
    }

    public function testListFilterRefusalMatchesRestWhereRestCanExpressIt(): void
    {
        foreach ([['status' => 'finie'], ['category' => 'Autre'], ['status' => '']] as $filters) {
            [, $text] = $this->err('list_tasks', $filters);
            $this->assertParity($text, $this->rest('GET', '/api/v1/tasks', null, $filters), 422, json_decode($text, true)['error']['code']);
        }
    }

    public function testUnknownTaskGivesTaskNotFoundEverywhere(): void
    {
        $unknown = 424242;
        $cases = [
            ['get_task', ['id' => $unknown], 'GET', "/api/v1/tasks/$unknown", null],
            ['update_task', ['id' => $unknown, 'title' => 'x'], 'PATCH', "/api/v1/tasks/$unknown", ['title' => 'x']],
            ['delete_task', ['id' => $unknown, 'confirm' => true], 'DELETE', "/api/v1/tasks/$unknown", null],
            ['add_required_action', ['id' => $unknown, 'required_task_id' => 1], 'PUT', "/api/v1/tasks/$unknown/required-actions/1", null],
            ['remove_required_action', ['id' => $unknown, 'required_task_id' => 1], 'DELETE', "/api/v1/tasks/$unknown/required-actions/1", null],
            ['change_task_status', ['id' => $unknown, 'status' => 'done'], 'PATCH', "/api/v1/tasks/$unknown", ['status' => 'done']],
        ];
        foreach ($cases as [$tool, $args, $method, $path, $body]) {
            [, $text] = $this->err($tool, $args, 'task_not_found');
            $this->assertParity($text, $this->rest($method, $path, $body), 404, 'task_not_found');
        }
    }

    public function testUpdateRefusalOrderIsNotFoundBeforeValidationLikeRest(): void
    {
        [, $text] = $this->err('update_task', ['id' => 424242, 'title' => ''], 'task_not_found');
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/424242', ['title' => '']), 404, 'task_not_found');
    }

    public function testDeleteRequiredTaskIsRefusedWithDependentsAndTaskIntact(): void
    {
        $a = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $b = $this->make(['title' => 'B', 'project' => 'Divers Pro']);
        $this->ok('add_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        [$e, $text] = $this->err('delete_task', ['id' => $b['id'], 'confirm' => true], 'task_is_required');
        $this->assertParity($text, $this->rest('DELETE', '/api/v1/tasks/' . $b['id']), 409, 'task_is_required');
        self::assertSame([['id' => $a['id'], 'title' => 'A']], $e['details']['dependents']);
        self::assertSame(2, $this->rows());
        self::assertSame(1, $this->links());
    }

    public function testDependencyRefusalsCreateNoLink(): void
    {
        $a = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $b = $this->make(['title' => 'B', 'project' => 'Divers Pro']);
        $c = $this->make(['title' => 'C', 'project' => 'Divers Pro']);
        // Auto-lien.
        [, $text] = $this->err('add_required_action', ['id' => $a['id'], 'required_task_id' => $a['id']], 'dependency_self');
        $this->assertParity($text, $this->rest('PUT', "/api/v1/tasks/{$a['id']}/required-actions/{$a['id']}"), 422, 'dependency_self');
        // Action requise inconnue.
        [, $text] = $this->err('add_required_action', ['id' => $a['id'], 'required_task_id' => 424242], 'required_task_not_found');
        $this->assertParity($text, $this->rest('PUT', "/api/v1/tasks/{$a['id']}/required-actions/424242"), 422, 'required_task_not_found');
        self::assertSame(0, $this->links());
        // A -> B puis B -> A : cycle direct.
        $this->ok('add_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        [, $text] = $this->err('add_required_action', ['id' => $b['id'], 'required_task_id' => $a['id']], 'dependency_cycle');
        $this->assertParity($text, $this->rest('PUT', "/api/v1/tasks/{$b['id']}/required-actions/{$a['id']}"), 409, 'dependency_cycle');
        self::assertSame(1, $this->links());
        // A -> B -> C puis C -> A : cycle indirect.
        $this->ok('add_required_action', ['id' => $b['id'], 'required_task_id' => $c['id']]);
        [, $text] = $this->err('add_required_action', ['id' => $c['id'], 'required_task_id' => $a['id']], 'dependency_cycle');
        $this->assertParity($text, $this->rest('PUT', "/api/v1/tasks/{$c['id']}/required-actions/{$a['id']}"), 409, 'dependency_cycle');
        self::assertSame(2, $this->links());
        self::assertSame([], $this->row($c['id'])['required_actions']);
    }

    public function testStatusRefusalsMatchRestAndLeaveTaskUnchanged(): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $this->backdate($t['id']);
        $before = $this->row($t['id']);
        // todo -> done interdit; même état interdit.
        foreach (['done', 'todo'] as $target) {
            [, $text] = $this->err('change_task_status', ['id' => $t['id'], 'status' => $target], 'status_transition_invalid');
            $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['status' => $target]), 409, 'status_transition_invalid');
        }
        // Valeur invalide, absente, de mauvais type.
        [, $text] = $this->err('change_task_status', ['id' => $t['id'], 'status' => 'finie'], 'status_invalid', 'status');
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['status' => 'finie']), 422, 'status_invalid');
        [, $text] = $this->err('change_task_status', ['id' => $t['id']], 'field_type_invalid', 'status');
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['status' => null]), 422, 'field_type_invalid');
        $this->err('change_task_status', ['id' => $t['id'], 'status' => 1], 'field_type_invalid', 'status');
        self::assertSame($before, $this->row($t['id']));
    }

    public function testCompletingWithPendingPrerequisitesIsRefusedWithTheList(): void
    {
        $a = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        $b = $this->make(['title' => 'B', 'project' => 'Divers Pro']);
        $this->ok('add_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        $this->ok('change_task_status', ['id' => $a['id'], 'status' => 'in_progress']);
        $this->backdate($a['id']);
        $before = $this->row($a['id']);
        [$e, $text] = $this->err('change_task_status', ['id' => $a['id'], 'status' => 'done'], 'required_actions_not_done');
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $a['id'], ['status' => 'done']), 409, 'required_actions_not_done');
        self::assertNotEmpty($e['details']);
        self::assertStringContainsString('"B"', $text);
        self::assertSame($before, $this->row($a['id']));
        // Dans l'ordre : prérequis terminé, puis la tâche.
        $this->ok('change_task_status', ['id' => $b['id'], 'status' => 'in_progress']);
        $this->ok('change_task_status', ['id' => $b['id'], 'status' => 'done']);
        self::assertSame('done', $this->ok('change_task_status', ['id' => $a['id'], 'status' => 'done'])['status']);
    }

    public function testNextDueDateOutOfRangeIsRefusedAndTaskUnchanged(): void
    {
        $t = $this->make(['title' => 'Fin', 'project' => 'Divers Pro', 'due_date' => '9999-12-31', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->ok('change_task_status', ['id' => $t['id'], 'status' => 'in_progress']);
        $before = $this->row($t['id']);
        [, $text] = $this->err('change_task_status', ['id' => $t['id'], 'status' => 'done'], 'next_due_date_out_of_range');
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['status' => 'done']), 409, 'next_due_date_out_of_range');
        self::assertSame($before, $this->row($t['id']));
        self::assertSame(1, $this->rows());
    }

    public function testErrorTextOmitsEmptyDetailsLikeRest(): void
    {
        $t = $this->make(['title' => 'A', 'project' => 'Divers Pro']);
        [$e] = $this->err('change_task_status', ['id' => $t['id'], 'status' => 'done'], 'status_transition_invalid');
        self::assertIsString($e['message']);
        foreach (['trace', 'SQLSTATE', 'PDO', '.php'] as $leak) {
            self::assertStringNotContainsString($leak, json_encode($e));
        }
    }

    public function testBigIntegerIdIsRefusedAsArgumentInvalid(): void
    {
        $text = $this->text($this->rpc('get_task', '{"id":99999999999999999999}'), true);
        self::assertSame('argument_invalid', json_decode($text, true)['error']['code']);
    }

    // --- Parcours ---

    public function testEndToEndJourneyThroughMcpOnly(): void
    {
        $b = $this->ok('create_task', ['title' => 'Prérequis', 'project' => 'Divers Pro']);
        $a = $this->ok('create_task', ['title' => 'Principale', 'project' => 'Divers Pro', 'due_date' => '2026-10-31']);
        $linked = $this->ok('add_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        self::assertCount(1, $linked['required_actions']);
        self::assertSame('Principale 2', $this->ok('update_task', ['id' => $a['id'], 'title' => 'Principale 2'])['title']);
        self::assertSame(2, count($this->ok('list_tasks')));
        $this->err('change_task_status', ['id' => $a['id'], 'status' => 'done'], 'status_transition_invalid');
        $this->ok('change_task_status', ['id' => $b['id'], 'status' => 'in_progress']);
        $this->ok('change_task_status', ['id' => $b['id'], 'status' => 'done']);
        $this->ok('change_task_status', ['id' => $a['id'], 'status' => 'in_progress']);
        self::assertSame('done', $this->ok('change_task_status', ['id' => $a['id'], 'status' => 'done'])['status']);
        $this->err('delete_task', ['id' => $b['id'], 'confirm' => true], 'task_is_required');
        $this->ok('remove_required_action', ['id' => $a['id'], 'required_task_id' => $b['id']]);
        self::assertSame(['deleted' => true, 'id' => $b['id']], $this->ok('delete_task', ['id' => $b['id'], 'confirm' => true]));
        self::assertSame([$a['id']], array_column($this->ok('list_tasks'), 'id'));
    }

    // --- Projets configurables (catégorie dérivée) ---

    /** @return array<string,array<string,mixed>> outils par nom */
    private function tools(): array
    {
        $r = $this->kernel->handle(new Request('POST', '/mcp', ['host' => '127.0.0.1:8080', 'content-type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        $out = [];
        foreach (json_decode($r->body, true, 64, JSON_THROW_ON_ERROR)['result']['tools'] as $tool) {
            $out[$tool['name']] = $tool;
        }

        return $out;
    }

    public function testProjectEnumsAreReadFromTheDatabaseOnEveryToolsList(): void
    {
        $names = ['Divers Perso', 'Divers Pro', 'Erendil', 'Jardin/Maison', 'MS-HR', 'OPF']; // tri lower(name)
        $tools = $this->tools();
        foreach ([['create_task'], ['update_task'], ['list_tasks']] as [$name]) {
            self::assertSame($names, $tools[$name]['inputSchema']['properties']['project']['enum'], $name);
        }
        self::assertArrayNotHasKey('category', $tools['create_task']['inputSchema']['properties']);
        self::assertArrayNotHasKey('category', $tools['update_task']['inputSchema']['properties']);
        self::assertSame(['title', 'project'], $tools['create_task']['inputSchema']['required']);
        self::assertSame(['Pro', 'Perso'], $tools['list_tasks']['inputSchema']['properties']['category']['enum']);

        // Création, renommage et suppression : le prochain tools/list les reflète (aucune constante en dur).
        $this->pdo->exec("INSERT INTO projects (name, category) VALUES ('Nouveau', 'Pro')");
        $this->pdo->exec("UPDATE projects SET name = 'Maison' WHERE name = 'Jardin/Maison'");
        $this->pdo->exec("DELETE FROM projects WHERE name = 'Erendil'");
        $enum = $this->tools()['create_task']['inputSchema']['properties']['project']['enum'];
        self::assertSame(['Divers Perso', 'Divers Pro', 'Maison', 'MS-HR', 'Nouveau', 'OPF'], $enum);
    }

    public function testCategoryIsDerivedFromTheProjectAndNeverWritable(): void
    {
        $t = $this->ok('create_task', ['title' => 'T', 'project' => 'MS-HR']);
        self::assertSame('Pro', $t['category']);
        $moved = $this->ok('update_task', ['id' => $t['id'], 'project' => 'OPF']);
        self::assertSame(['OPF', 'Perso'], [$moved['project'], $moved['category']], 'la catégorie suit le projet');
        $before = $this->row($t['id']);
        [, $text] = $this->err('update_task', ['id' => $t['id'], 'category' => 'Pro'], 'field_unknown', 'category');
        $this->assertParity($text, $this->rest('PATCH', '/api/v1/tasks/' . $t['id'], ['category' => 'Pro']), 422, 'field_unknown');
        self::assertSame($before, $this->row($t['id']));
        $this->err('create_task', ['title' => 'X', 'project' => 'OPF', 'category' => 'Perso'], 'field_unknown', 'category');
        self::assertSame(1, $this->rows());
        self::assertSame([$t['id']], array_column($this->ok('list_tasks', ['category' => 'Perso', 'project' => 'OPF']), 'id'));
    }

    public function testListFilterWithUnknownProjectIsRefusedLikeRest(): void
    {
        foreach (['Inexistant', 'opf', ''] as $name) {
            [, $text] = $this->err('list_tasks', ['project' => $name], 'project_invalid', 'project');
            $this->assertParity($text, $this->rest('GET', '/api/v1/tasks', null, ['project' => $name]), 422, 'project_invalid');
        }
    }
}
