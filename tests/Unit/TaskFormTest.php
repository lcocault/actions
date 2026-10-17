<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Portal\TaskForm;
use PHPUnit\Framework\TestCase;

final class TaskFormTest extends TestCase
{
    public function testEmptyFieldsBecomeNull(): void
    {
        $in = TaskForm::toInput(['title' => 'T', 'due_date' => '', 'recurrence_interval' => '', 'recurrence_unit' => '', 'estimated_minutes' => '', 'description' => '', 'project' => '', 'conclusions' => '', 'importance' => 'Majeure']);
        self::assertSame(['title' => 'T', 'due_date' => null, 'recurrence' => null, 'estimated_minutes' => null, 'description' => null, 'project' => null, 'conclusions' => null, 'importance' => 'Majeure'], $in);
    }

    public function testDigitsBecomeIntegers(): void
    {
        $in = TaskForm::toInput(['title' => 'T', 'due_date' => '2026-02-28', 'recurrence_interval' => '2', 'recurrence_unit' => 'month', 'estimated_minutes' => '90', 'description' => "a\nb"]);
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

    public function testOnlyTheEightFieldsAreForwardedAndForgedCategoryIsIgnored(): void
    {
        $in = TaskForm::toInput(['title' => 'T', 'category' => 'Pro', 'status' => 'done', 'required_actions' => [1], 'id' => '5', '_csrf' => 'x']);
        self::assertSame(['title', 'due_date', 'recurrence', 'estimated_minutes', 'description', 'project', 'conclusions', 'importance'], array_keys($in));
    }

    public function testNonStringValuesAreForwardedForTheUseCaseToRefuse(): void
    {
        self::assertSame(['x'], TaskForm::toInput(['title' => ['x']])['title']);
        self::assertSame(['title' => '', 'due_date' => '', 'recurrence_interval' => '', 'recurrence_unit' => '', 'estimated_minutes' => '', 'description' => '', 'project' => '', 'conclusions' => '', 'importance' => ''], TaskForm::valuesFromForm(['title' => ['x']]));
    }

    public function testProjectAndConclusionsAreTransportedAndEmptyBecomesNull(): void
    {
        $in = TaskForm::toInput(['project' => 'MS-HR', 'conclusions' => "a\nb"]);
        self::assertSame('MS-HR', $in['project']);
        self::assertSame("a\nb", $in['conclusions']);
        self::assertSame('ms-hr', TaskForm::toInput(['project' => 'ms-hr'])['project'], 'aucune normalisation : le cas d\'usage refuse');
        self::assertNull(TaskForm::toInput(['project' => '', 'conclusions' => ''])['project']);
        self::assertSame(['x'], TaskForm::toInput(['project' => ['x']])['project']);
        self::assertSame('Erendil', TaskForm::valuesFromTask(['title' => 't', 'project' => 'Erendil', 'conclusions' => 'c', 'importance' => 'Mineure'])['project']);
        self::assertSame('', TaskForm::valuesFromTask(['title' => 't', 'project' => 'OPF', 'conclusions' => null, 'importance' => 'Mineure'])['conclusions']);
    }

    public function testImportanceIsForwardedAsIsWithoutEmptyToNullConversion(): void
    {
        self::assertSame('Critique', TaskForm::toInput(['importance' => 'Critique'])['importance']);
        self::assertSame('', TaskForm::toInput(['importance' => ''])['importance'], 'vide transmis tel quel : le cas d\'usage refuse');
        self::assertSame('', TaskForm::toInput([])['importance'], 'champ absent : transmis vide, donc refusé');
        self::assertSame('critique', TaskForm::toInput(['importance' => 'critique'])['importance'], 'aucune normalisation');
        self::assertSame(['x'], TaskForm::toInput(['importance' => ['x']])['importance']);
        self::assertSame('Mineure', TaskForm::emptyValues()['importance'], 'valeur initiale de création');
        self::assertSame('Critique', TaskForm::valuesFromTask(['title' => 't', 'importance' => 'Critique'])['importance']);
        self::assertSame('Majeure', TaskForm::valuesFromForm(['importance' => 'Majeure'])['importance']);
    }
}
