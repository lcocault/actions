<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\InvalidTask;
use Actions\Application\TaskValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Une assertion de `code` par règle de validation (sans base). */
final class TaskValidatorTest extends TestCase
{
    private function create(array $data): array
    {
        return (new TaskValidator())->validateCreate($data);
    }

    private function assertRefused(string $code, callable $fn, ?string $field = null): void
    {
        try {
            $fn();
        } catch (InvalidTask $e) {
            self::assertSame($code, $e->code());
            if ($field !== null) {
                self::assertSame(['field' => $field], $e->details());
            }

            return;
        }
        self::fail("InvalidTask($code) attendue.");
    }

    public function testMinimalTaskGivesNullOptionalFields(): void
    {
        self::assertSame([
            'title' => 'Payer', 'category' => 'Pro', 'due_date' => null,
            'recurrence' => null, 'estimated_minutes' => null, 'description' => null,
        ], $this->create(['title' => 'Payer', 'category' => 'Pro']));
    }

    public function testTitleIsKeptAsIs(): void
    {
        self::assertSame('  a b ', $this->create(['title' => '  a b ', 'category' => 'Perso'])['title']);
    }

    /** @return array<string,array{mixed}> */
    public static function blankTitles(): array
    {
        return [
            'vide' => [''], 'espaces' => ['   '], 'tabulations' => ["\t\t"], 'retours' => ["\n\r\n"],
            'mélange' => [" \t\n \r "], 'espace insécable' => ["\u{00A0}"], 'espace large' => ["\u{2003} \u{3000}"],
        ];
    }

    #[DataProvider('blankTitles')]
    public function testBlankTitleRefused(string $title): void
    {
        $this->assertRefused('title_blank', fn () => $this->create(['title' => $title, 'category' => 'Pro']), 'title');
    }

    public function testMissingTitleRefused(): void
    {
        $this->assertRefused('title_blank', fn () => $this->create(['category' => 'Pro']));
    }

    public function testTitleLength(): void
    {
        self::assertSame(255, mb_strlen($this->create(['title' => str_repeat('é', 255), 'category' => 'Pro'])['title']));
        $this->assertRefused('title_too_long', fn () => $this->create(['title' => str_repeat('é', 256), 'category' => 'Pro']));
    }

    public function testInvalidUtf8AndNulRefused(): void
    {
        $this->assertRefused('text_invalid', fn () => $this->create(['title' => "a\xC3\x28", 'category' => 'Pro']));
        $this->assertRefused('text_invalid', fn () => $this->create(['title' => "a\0b", 'category' => 'Pro']));
        $this->assertRefused('text_invalid', fn () => $this->create(['title' => 'a', 'category' => 'Pro', 'description' => "x\0"]), 'description');
    }

    #[DataProvider('badCategories')]
    public function testCategoryRefused(mixed $category): void
    {
        $this->assertRefused('category_invalid', fn () => $this->create(['title' => 'a', 'category' => $category]), 'category');
    }

    /** @return array<string,array{mixed}> */
    public static function badCategories(): array
    {
        return ['inconnue' => ['Autre'], 'casse' => ['pro'], 'vide' => [''], 'espace' => ['Pro ']];
    }

    public function testMissingCategoryRefused(): void
    {
        $this->assertRefused('category_invalid', fn () => $this->create(['title' => 'a']));
    }

    public function testDueDate(): void
    {
        self::assertSame('2020-01-31', $this->create(['title' => 'a', 'category' => 'Pro', 'due_date' => '2020-01-31'])['due_date']);
        self::assertSame('2024-02-29', $this->create(['title' => 'a', 'category' => 'Pro', 'due_date' => '2024-02-29'])['due_date']);
        self::assertSame('0001-01-01', $this->create(['title' => 'a', 'category' => 'Pro', 'due_date' => '0001-01-01'])['due_date']);
    }

    #[DataProvider('badDates')]
    public function testDueDateRefused(string $date): void
    {
        $this->assertRefused('due_date_invalid', fn () => $this->create(['title' => 'a', 'category' => 'Pro', 'due_date' => $date]), 'due_date');
    }

    /** @return array<string,array{string}> */
    public static function badDates(): array
    {
        return [
            '30 février' => ['2026-02-30'], '29 février non bissextile' => ['2025-02-29'], 'mois 13' => ['2026-13-01'],
            'format' => ['12/10/2026'], 'sans zéro' => ['2026-1-1'], 'année 0' => ['0000-01-01'],
            'heure' => ['2026-10-11T00:00:00'], 'saut de ligne final' => ["2026-10-11\n"], 'vide' => [''],
        ];
    }

    public function testRecurrenceValid(): void
    {
        $t = $this->create(['title' => 'a', 'category' => 'Pro', 'due_date' => '2026-10-11', 'recurrence' => ['interval' => 2, 'unit' => 'week']]);
        self::assertSame(['interval' => 2, 'unit' => 'week'], $t['recurrence']);
    }

    /** @return array<string,array{string,array<string,mixed>,string}> */
    public static function badRecurrences(): array
    {
        return [
            'intervalle 0' => ['recurrence_interval_invalid', ['interval' => 0, 'unit' => 'day'], 'recurrence.interval'],
            'intervalle négatif' => ['recurrence_interval_invalid', ['interval' => -1, 'unit' => 'day'], 'recurrence.interval'],
            'intervalle hors int4' => ['recurrence_interval_invalid', ['interval' => 2147483648, 'unit' => 'day'], 'recurrence.interval'],
            'intervalle décimal' => ['field_type_invalid', ['interval' => 1.5, 'unit' => 'day'], 'recurrence.interval'],
            'intervalle chaîne' => ['field_type_invalid', ['interval' => '2', 'unit' => 'day'], 'recurrence.interval'],
            'unité inconnue' => ['recurrence_unit_invalid', ['interval' => 1, 'unit' => 'jour'], 'recurrence.unit'],
            'unité type' => ['field_type_invalid', ['interval' => 1, 'unit' => 3], 'recurrence.unit'],
            'sans unité' => ['recurrence_incomplete', ['interval' => 1], 'recurrence'],
            'sans intervalle' => ['recurrence_incomplete', ['unit' => 'day'], 'recurrence'],
            'unité nulle' => ['recurrence_incomplete', ['interval' => 1, 'unit' => null], 'recurrence'],
            'objet vide' => ['recurrence_incomplete', [], 'recurrence'],
            'clé inconnue' => ['field_unknown', ['interval' => 1, 'unit' => 'day', 'x' => 1], 'recurrence.x'],
        ];
    }

    #[DataProvider('badRecurrences')]
    public function testRecurrenceRefused(string $code, array $recurrence, string $field): void
    {
        $this->assertRefused($code, fn () => $this->create([
            'title' => 'a', 'category' => 'Pro', 'due_date' => '2026-10-11', 'recurrence' => $recurrence,
        ]), $field);
    }

    public function testRecurrenceNotAnObject(): void
    {
        $this->assertRefused('field_type_invalid', fn () => $this->create([
            'title' => 'a', 'category' => 'Pro', 'due_date' => '2026-10-11', 'recurrence' => 'weekly',
        ]), 'recurrence');
    }

    public function testRecurrenceWithoutDueDateRefused(): void
    {
        $this->assertRefused('recurrence_requires_due_date', fn () => $this->create([
            'title' => 'a', 'category' => 'Pro', 'recurrence' => ['interval' => 1, 'unit' => 'day'],
        ]), 'recurrence');
    }

    public function testEstimatedMinutes(): void
    {
        self::assertSame(1, $this->create(['title' => 'a', 'category' => 'Pro', 'estimated_minutes' => 1])['estimated_minutes']);
        self::assertSame(2147483647, $this->create(['title' => 'a', 'category' => 'Pro', 'estimated_minutes' => 2147483647])['estimated_minutes']);
    }

    /** @return array<string,array{string,mixed}> */
    public static function badMinutes(): array
    {
        return [
            'zéro' => ['estimated_minutes_invalid', 0], 'négatif' => ['estimated_minutes_invalid', -5],
            'hors int4' => ['estimated_minutes_invalid', 2147483648],
            'décimal' => ['field_type_invalid', 1.5], 'chaîne' => ['field_type_invalid', '30'], 'booléen' => ['field_type_invalid', true],
        ];
    }

    #[DataProvider('badMinutes')]
    public function testEstimatedMinutesRefused(string $code, mixed $value): void
    {
        $this->assertRefused($code, fn () => $this->create(['title' => 'a', 'category' => 'Pro', 'estimated_minutes' => $value]), 'estimated_minutes');
    }

    public function testWrongTypes(): void
    {
        $this->assertRefused('field_type_invalid', fn () => $this->create(['title' => 12, 'category' => 'Pro']), 'title');
        $this->assertRefused('field_type_invalid', fn () => $this->create(['title' => null, 'category' => 'Pro']), 'title');
        $this->assertRefused('field_type_invalid', fn () => $this->create(['title' => 'a', 'category' => 1]), 'category');
        $this->assertRefused('field_type_invalid', fn () => $this->create(['title' => 'a', 'category' => 'Pro', 'due_date' => 20261011]), 'due_date');
        $this->assertRefused('field_type_invalid', fn () => $this->create(['title' => 'a', 'category' => 'Pro', 'description' => []]), 'description');
    }

    public function testEmptyDescriptionIsKept(): void
    {
        self::assertSame('', $this->create(['title' => 'a', 'category' => 'Pro', 'description' => ''])['description']);
    }

    public function testUnknownKeyRefused(): void
    {
        $this->assertRefused('field_unknown', fn () => $this->create(['title' => 'a', 'category' => 'Pro', 'titel' => 'x']), 'titel');
        foreach (['id', 'required_actions', 'created_at', 'updated_at'] as $key) {
            $this->assertRefused('field_unknown', fn () => $this->create(['title' => 'a', 'category' => 'Pro', $key => 1]), $key);
        }
    }

    public function testFailFastReportsFirstError(): void
    {
        // titre invalide ET catégorie invalide : l'ordre des champs fait foi (titre d'abord).
        $this->assertRefused('title_blank', fn () => $this->create(['title' => ' ', 'category' => 'X']));
        // clé inconnue avant le reste.
        $this->assertRefused('field_unknown', fn () => $this->create(['title' => ' ', 'category' => 'X', 'zzz' => 1]));
    }

    private function current(): array
    {
        return [
            'title' => 'a', 'category' => 'Pro', 'due_date' => '2026-10-11',
            'recurrence' => ['interval' => 1, 'unit' => 'day'], 'estimated_minutes' => 5, 'description' => 'd',
        ];
    }

    public function testUpdateMergesAndNullClears(): void
    {
        $v = new TaskValidator();
        $m = $v->validateUpdate($this->current(), ['recurrence' => null, 'due_date' => null, 'description' => null]);
        self::assertNull($m['recurrence']);
        self::assertNull($m['due_date']);
        self::assertNull($m['description']);
        self::assertSame(5, $m['estimated_minutes']);
        self::assertSame('a', $m['title']);
    }

    public function testUpdateClearingDueDateOfRecurringTaskRefused(): void
    {
        $this->assertRefused('recurrence_requires_due_date', fn () => (new TaskValidator())->validateUpdate($this->current(), ['due_date' => null]));
    }

    public function testUpdateAddingRecurrenceToTaskWithoutDueDateRefused(): void
    {
        $c = $this->current();
        $c['due_date'] = null;
        $c['recurrence'] = null;
        $this->assertRefused('recurrence_requires_due_date', fn () => (new TaskValidator())->validateUpdate($c, ['recurrence' => ['interval' => 1, 'unit' => 'day']]));
    }

    public function testUpdateRefusesTitleNullAndUnknownKeys(): void
    {
        $this->assertRefused('field_type_invalid', fn () => (new TaskValidator())->validateUpdate($this->current(), ['title' => null]), 'title');
        $this->assertRefused('field_unknown', fn () => (new TaskValidator())->validateUpdate($this->current(), ['id' => 3]), 'id');
        $this->assertRefused('field_unknown', fn () => (new TaskValidator())->validateUpdate($this->current(), ['required_actions' => []]), 'required_actions');
    }
}
