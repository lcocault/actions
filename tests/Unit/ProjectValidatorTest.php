<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\InvalidProject;
use Actions\Application\ProjectValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Une assertion de `code` par règle de validation d'un projet (sans base). */
final class ProjectValidatorTest extends TestCase
{
    private function assertRefused(string $code, callable $fn, ?string $field = null): void
    {
        try {
            $fn();
        } catch (InvalidProject $e) {
            self::assertSame($code, $e->code());
            if ($field !== null) {
                self::assertSame(['field' => $field], $e->details());
            }

            return;
        }
        self::fail("InvalidProject($code) attendue.");
    }

    private function create(array $data): array
    {
        return (new ProjectValidator())->validateCreate($data);
    }

    private function update(array $changes): array
    {
        return (new ProjectValidator())->validateUpdate(['name' => 'OPF', 'category' => 'Perso'], $changes);
    }

    public function testValidCreationKeepsValuesAsIs(): void
    {
        self::assertSame(['name' => '  Mon projet é/ü ', 'category' => 'Pro'], $this->create(['name' => '  Mon projet é/ü ', 'category' => 'Pro']));
        self::assertSame(255, mb_strlen($this->create(['name' => str_repeat('é', 255), 'category' => 'Perso'])['name']));
        self::assertSame('opf', $this->create(['name' => 'opf', 'category' => 'Pro'])['name'], 'la casse n\'est pas contrôlée ici (unicité : base)');
    }

    /** @return array<string,array{mixed}> */
    public static function blankNames(): array
    {
        return ['vide' => [''], 'espaces' => ['   '], 'tabulations' => ["\t\t"], 'retours' => ["\n\r\n"], 'insécable' => ["\u{00A0}"], 'large' => ["\u{2003} \u{3000}"]];
    }

    #[DataProvider('blankNames')]
    public function testBlankNameRefused(string $name): void
    {
        $this->assertRefused('project_name_blank', fn () => $this->create(['name' => $name, 'category' => 'Pro']), 'name');
        $this->assertRefused('project_name_blank', fn () => $this->update(['name' => $name]), 'name');
    }

    public function testNameLength(): void
    {
        $this->assertRefused('project_name_too_long', fn () => $this->create(['name' => str_repeat('é', 256), 'category' => 'Pro']), 'name');
        $this->assertRefused('project_name_too_long', fn () => $this->update(['name' => str_repeat('a', 256)]), 'name');
    }

    public function testInvalidUtf8AndNulRefused(): void
    {
        $this->assertRefused('text_invalid', fn () => $this->create(['name' => "a\xC3\x28", 'category' => 'Pro']), 'name');
        $this->assertRefused('text_invalid', fn () => $this->create(['name' => "a\0b", 'category' => 'Pro']), 'name');
    }

    /** @return array<string,array{mixed}> */
    public static function badCategories(): array
    {
        return ['inconnue' => ['Autre'], 'casse' => ['pro'], 'vide' => [''], 'espace' => ['Pro ']];
    }

    #[DataProvider('badCategories')]
    public function testCategoryRefused(string $category): void
    {
        $this->assertRefused('category_invalid', fn () => $this->create(['name' => 'X', 'category' => $category]), 'category');
        $this->assertRefused('category_invalid', fn () => $this->update(['category' => $category]), 'category');
    }

    public function testMissingFieldsAreTreatedAsInvalidValues(): void
    {
        $this->assertRefused('project_name_blank', fn () => $this->create(['category' => 'Pro']), 'name');
        $this->assertRefused('category_invalid', fn () => $this->create(['name' => 'X']), 'category');
        $this->assertRefused('project_name_blank', fn () => $this->create([]), 'name');
    }

    public function testWrongTypesAndNullAreRefused(): void
    {
        foreach ([12, null, true, ['a'], 1.5] as $value) {
            $this->assertRefused('field_type_invalid', fn () => $this->create(['name' => $value, 'category' => 'Pro']), 'name');
            $this->assertRefused('field_type_invalid', fn () => $this->create(['name' => 'X', 'category' => $value]), 'category');
            $this->assertRefused('field_type_invalid', fn () => $this->update(['name' => $value]), 'name');
            $this->assertRefused('field_type_invalid', fn () => $this->update(['category' => $value]), 'category');
        }
    }

    public function testUnknownKeysRefusedIncludingDerivedOnes(): void
    {
        foreach (['id', 'task_count', 'tasks_recategorized', 'color', 'Name'] as $key) {
            $this->assertRefused('field_unknown', fn () => $this->create(['name' => 'X', 'category' => 'Pro', $key => 1]), $key);
            $this->assertRefused('field_unknown', fn () => $this->update([$key => 1]), $key);
        }
        // Une clé inconnue est refusée avant le reste.
        $this->assertRefused('field_unknown', fn () => $this->create(['name' => ' ', 'zzz' => 1]), 'zzz');
        $this->assertRefused('project_name_blank', fn () => $this->create(['name' => ' ', 'category' => 'X']), 'name');
    }

    public function testUpdateMergesAndAbsentKeysAreUnchanged(): void
    {
        self::assertSame(['name' => 'OPF', 'category' => 'Perso'], $this->update([]));
        self::assertSame(['name' => 'opf', 'category' => 'Perso'], $this->update(['name' => 'opf']));
        self::assertSame(['name' => 'OPF', 'category' => 'Pro'], $this->update(['category' => 'Pro']));
        self::assertSame(['name' => 'Autre', 'category' => 'Pro'], $this->update(['name' => 'Autre', 'category' => 'Pro']));
    }
}
