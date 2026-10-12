<?php

declare(strict_types=1);

namespace Actions\Http;

use Actions\Application\DomainError;
use Actions\Application\InvalidTask;

/** Table unique code d'erreur de domaine -> statut HTTP, partagée par REST et portail. */
final class DomainStatus
{
    /** Statut HTTP par code d'erreur de domaine; un code absent de la table est une erreur interne. */
    private const BY_CODE = [
        'task_not_found' => 404,
        'required_task_not_found' => 422,
        'dependency_self' => 422,
        'dependency_cycle' => 409,
        'task_is_required' => 409,
        'status_transition_invalid' => 409,
        'required_actions_not_done' => 409,
        'next_due_date_out_of_range' => 409,
    ];

    /** Les codes de validation forment une famille ouverte : statut par classe (422), puis par table; null = inconnu. */
    public static function of(DomainError $e): ?int
    {
        return $e instanceof InvalidTask ? 422 : (self::BY_CODE[$e->code()] ?? null);
    }
}
