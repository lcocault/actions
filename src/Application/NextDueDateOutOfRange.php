<?php

declare(strict_types=1);

namespace Actions\Application;

final class NextDueDateOutOfRange extends DomainError
{
    public function __construct(int $id, string $dueDate)
    {
        parent::__construct(
            'next_due_date_out_of_range',
            'La date butée suivante dépasse la date maximale permise.',
            ['id' => $id, 'due_date' => $dueDate],
        );
    }
}
