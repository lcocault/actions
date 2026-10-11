<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\TaskStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskStatusTest extends TestCase
{
    /** @return iterable<string,array{TaskStatus,TaskStatus,bool}> les 9 couples */
    public static function pairs(): iterable
    {
        $allowed = ['todo>in_progress', 'in_progress>done', 'in_progress>todo', 'done>todo'];
        foreach (TaskStatus::cases() as $from) {
            foreach (TaskStatus::cases() as $to) {
                yield $from->value . '>' . $to->value => [$from, $to, in_array($from->value . '>' . $to->value, $allowed, true)];
            }
        }
    }

    #[DataProvider('pairs')]
    public function testTransitionTable(TaskStatus $from, TaskStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    public function testCodes(): void
    {
        self::assertSame(['todo', 'in_progress', 'done'], TaskStatus::codes());
        self::assertNull(TaskStatus::tryFrom('Done'));
    }
}
