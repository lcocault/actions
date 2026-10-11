<?php

declare(strict_types=1);

namespace Actions\Application;

/** États d'une tâche (codes stables en base et dans les interfaces) et table unique des transitions permises. */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    /** @return list<self> */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Todo => [self::InProgress],
            self::InProgress => [self::Done, self::Todo],
            self::Done => [self::Todo],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
