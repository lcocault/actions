<?php

declare(strict_types=1);

namespace Actions\Portal;

/**
 * Formulaire de tâche <-> tableau pour TaskService. Traduction de transport seulement :
 * chaîne vide -> null, chaîne de chiffres -> entier; aucune règle (le cas d'usage valide et refuse).
 */
final class TaskForm
{
    public const FIELDS = ['title', 'due_date', 'recurrence_interval', 'recurrence_unit', 'estimated_minutes', 'description', 'project', 'conclusions'];

    /**
     * Les sept champs de TaskService, toujours tous présents (un champ vidé efface la valeur). Un champ `category`
     * forgé dans le POST est ignoré comme tout champ de formulaire inconnu (la catégorie découle du projet).
     *
     * @param array<string,mixed> $form
     * @return array<string,mixed>
     */
    public static function toInput(array $form): array
    {
        $interval = self::number($form['recurrence_interval'] ?? null);
        $unit = self::nullIfEmpty($form['recurrence_unit'] ?? null);

        return [
            'title' => $form['title'] ?? '',
            'due_date' => self::nullIfEmpty($form['due_date'] ?? null),
            'recurrence' => $interval === null && $unit === null ? null : ['interval' => $interval, 'unit' => $unit],
            'estimated_minutes' => self::number($form['estimated_minutes'] ?? null),
            'description' => self::nullIfEmpty($form['description'] ?? null),
            'project' => self::nullIfEmpty($form['project'] ?? null),
            'conclusions' => self::nullIfEmpty($form['conclusions'] ?? null),
        ];
    }

    /**
     * Valeurs à réafficher dans les champs à partir de la saisie brute (valeur non textuelle -> vide).
     *
     * @param array<string,mixed> $form
     * @return array<string,string>
     */
    public static function valuesFromForm(array $form): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = is_string($form[$field] ?? null) ? $form[$field] : '';
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $task forme `Task`
     * @return array<string,string>
     */
    public static function valuesFromTask(array $task): array
    {
        return [
            'title' => $task['title'],
            'due_date' => $task['due_date'] ?? '',
            'recurrence_interval' => isset($task['recurrence']) ? (string) $task['recurrence']['interval'] : '',
            'recurrence_unit' => $task['recurrence']['unit'] ?? '',
            'estimated_minutes' => isset($task['estimated_minutes']) ? (string) $task['estimated_minutes'] : '',
            'description' => $task['description'] ?? '',
            'project' => $task['project'] ?? '',
            'conclusions' => $task['conclusions'] ?? '',
        ];
    }

    /** @return array<string,string> */
    public static function emptyValues(): array
    {
        return array_fill_keys(self::FIELDS, '');
    }

    private static function nullIfEmpty(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }

    /** Chaîne de chiffres tenant dans un entier PHP -> int; vide -> null; le reste est transmis tel quel. */
    private static function number(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $trimmed = ltrim($value, '0');
            if ((string) (int) $value === ($trimmed === '' ? '0' : $trimmed)) {
                return (int) $value;
            }
        }

        return $value;
    }
}
