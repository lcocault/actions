<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Filtre d'urgence du portail (E1 : ni REST ni MCP). Pur : ni base ni horloge. S'appuie sur
 * UrgencyClassifier::placement, la même règle que la matrice de la vue projet (nombre d'une case = lignes de la liste).
 */
final class TaskUrgencyFilter
{
    /**
     * @param list<array<string,mixed>> $tasks tâches (Task) déjà lues; l'ordre est conservé
     * @param mixed $urgency null = filtre absent; sinon `week`, `month` ou `later`
     * @param bool $noDueDate garde les non terminées sans date butée (ET avec $urgency : résultat vide)
     * @param string $today AAAA-MM-JJ
     * @return list<array<string,mixed>>
     * @throws InvalidTask urgency_invalid
     */
    public static function apply(array $tasks, mixed $urgency, bool $noDueDate, string $today): array
    {
        if ($urgency !== null && (!is_string($urgency) || !in_array($urgency, UrgencyClassifier::COLUMNS, true))) {
            throw new InvalidTask('urgency_invalid', 'urgency', 'L\'échéance doit être week, month ou later.');
        }
        if ($urgency === null && !$noDueDate) {
            return $tasks;
        }
        $kept = [];
        foreach ($tasks as $task) {
            $place = UrgencyClassifier::placement((string) $task['status'], $task['due_date'], $today);
            if ($urgency !== null && !($place['zone'] === UrgencyClassifier::ZONE_GRID && $place['column'] === $urgency)) {
                continue;
            }
            if ($noDueDate && $place['zone'] !== UrgencyClassifier::ZONE_NO_DUE_DATE) {
                continue;
            }
            $kept[] = $task;
        }

        return $kept;
    }
}
