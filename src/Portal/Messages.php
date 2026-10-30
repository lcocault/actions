<?php

declare(strict_types=1);

namespace Actions\Portal;

use Actions\Application\DomainError;

/**
 * Message affiché pour une erreur de domaine : texte propre au portail pour les codes dont le message
 * de l'exception cite des codes techniques; repli sur le message français de l'exception.
 */
final class Messages
{
    private const BY_CODE = [
        'due_date_invalid' => 'Date invalide.',
        'recurrence_unit_invalid' => 'Choisissez une unité de périodicité.',
        'status_invalid' => 'État invalide.',
        'field_unknown' => 'Champ inconnu.',
        'field_type_invalid' => 'Valeur invalide.',
        'text_invalid' => 'Texte invalide.',
        'urgency_invalid' => 'Échéance inconnue. Choisissez en retard, moins d\'une semaine, moins d\'un mois ou au-delà.',
    ];

    public static function for(DomainError $e): string
    {
        $d = $e->details();

        return match ($e->code()) {
            'dependency_self' => 'Une tâche ne peut pas se requérir elle-même.',
            'dependency_cycle' => 'Ajouter cette action requise créerait un cycle.',
            'required_task_not_found' => 'Cette action requise n\'existe plus.',
            'status_transition_invalid' => 'Ce changement d\'état n\'est plus possible : la tâche est « '
                . Labels::status((string) ($d['from'] ?? '')) . ' », elle ne peut pas passer à « '
                . Labels::status((string) ($d['to'] ?? '')) . ' ». Rechargez la page.',
            'required_actions_not_done' => 'Terminaison impossible : ces actions requises ne sont pas terminées :',
            'next_due_date_out_of_range' => 'La prochaine échéance dépasserait le 31/12/9999 : la tâche ne peut pas être terminée.',
            default => self::BY_CODE[$e->code()] ?? $e->getMessage(),
        };
    }
}
