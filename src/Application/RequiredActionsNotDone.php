<?php

declare(strict_types=1);

namespace Actions\Application;

final class RequiredActionsNotDone extends DomainError
{
    /** @param list<array{id:int,title:string,status:string}> $requiredActions triées par id */
    public function __construct(int $id, array $requiredActions)
    {
        parent::__construct(
            'required_actions_not_done',
            'Des actions requises ne sont pas terminées.',
            ['id' => $id, 'required_actions' => $requiredActions],
        );
    }
}
