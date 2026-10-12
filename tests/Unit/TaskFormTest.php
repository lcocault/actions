<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Portal\TaskForm;
use PHPUnit\Framework\TestCase;

final class TaskFormTest extends TestCase
{
    public function testEmptyFieldsBecomeNull(): void
    {
        $in = TaskForm::toInput(['title' => 'T', 'category' => 'Pro', 'due_date' => '', 'recurrence_interval' => '', 'recurrence_unit' => '', 'estimated_minutes' => '', 'description' => '']);
        self::assertSame(['title' => 'T', 'category' => 'Pro', 'due_date' => null, 'recurrence' => null, 'estimated_minutes' => null, 'description' => null], $in);
    }

    public function testDigitsBecomeIntegers(): void
    {
        $in = TaskForm::toInput(['title' => 'T', 'category' => 'Perso', 'due_date' => '2026-02-28', 'recurrence_interval' => '2', 'recurrence_unit' => 'month', 'estimated_minutes' => '90', 'description' => "a\nb"]);
        self::assertSame(['interval' => 2, 'unit' => 'month'], $in['recurrence']);
        self::assertSame(90, $in['estimated_minutes']);
        self::assertSame('2026-02-28', $in['due_date']);
        self::assertSame("a\nb", $in['description']);
    }

    public function testOtherStringsAreLeftForTheUseCaseToRefuse(): void
    {
        $in = TaskForm::toInput(['recurrence_interval' => '-1', 'recurrence_unit' => 'month', 'estimated_minutes' => '99999999999999999999', 'title' => '  ']);
        self::assertSame(['interval' => '-1', 'unit' => 'month'], $in['recurrence']);
        self::assertSame('99999999999999999999', $in['estimated_minutes']);
        self::assertSame('  ', $in['title'], 'titre transmis tel quel');
        self::assertSame(0, TaskForm::toInput(['estimated_minutes' => '0'])['estimated_minutes']);
        self::assertSame(7, TaskForm::toInput(['estimated_minutes' => '007'])['estimated_minutes']);
    }

    public function testHalfFilledRecurrenceKeepsMissingPartAsNull(): void
    {
        self::assertSame(['interval' => 3, 'unit' => null], TaskForm::toInput(['recurrence_interval' => '3', 'recurrence_unit' => ''])['recurrence']);
        self::assertSame(['interval' => null, 'unit' => 'day'], TaskForm::toInput(['recurrence_interval' => '', 'recurrence_unit' => 'day'])['recurrence']);
    }

    public function testOnlyTheSixFieldsAreForwarded(): void
    {
        $in = TaskForm::toInput(['title' => 'T', 'category' => 'Pro', 'status' => 'done', 'required_actions' => [1], 'id' => '5', '_csrf' => 'x']);
        self::assertSame(['title', 'category', 'due_date', 'recurrence', 'estimated_minutes', 'description'], array_keys($in));
    }

    public function testNonStringValuesAreForwardedForTheUseCaseToRefuse(): void
    {
        self::assertSame(['x'], TaskForm::toInput(['title' => ['x']])['title']);
        self::assertSame(['title' => '', 'category' => '', 'due_date' => '', 'recurrence_interval' => '', 'recurrence_unit' => '', 'estimated_minutes' => '', 'description' => ''], TaskForm::valuesFromForm(['title' => ['x']]));
    }
}
