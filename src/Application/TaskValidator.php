<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Validation pure (sans base), fail-fast : la première erreur est levée.
 * Les valeurs sont conservées telles que fournies (aucun trim).
 */
final class TaskValidator
{
    private const FIELDS = ['title', 'category', 'due_date', 'recurrence', 'estimated_minutes', 'description', 'project', 'conclusions'];
    public const UNITS = ['day', 'week', 'month', 'year'];
    public const CATEGORIES = ['Pro', 'Perso'];
    /** Liste fermée des projets (répétée dans le CHECK tasks_project_valid, migration 0003). */
    public const PROJECTS = ['MS-HR', 'Jardin/Maison', 'OPF', 'Erendil'];
    private const INT4_MAX = 2147483647;
    private const TITLE_MAX = 255;

    /**
     * @param array<string,mixed> $data
     * @return array{title:string,category:string,due_date:?string,recurrence:?array{interval:int,unit:string},estimated_minutes:?int,description:?string,project:?string,conclusions:?string}
     * @throws InvalidTask
     */
    public function validateCreate(array $data): array
    {
        $this->rejectUnknownKeys($data);
        $state = [
            'title' => null, 'category' => null, 'due_date' => null,
            'recurrence' => null, 'estimated_minutes' => null, 'description' => null,
            'project' => null, 'conclusions' => null,
        ];
        // Obligatoires : une valeur absente est traitée comme une valeur invalide du champ.
        if (!array_key_exists('title', $data)) {
            throw new InvalidTask('title_blank', 'title', 'Le titre est obligatoire.');
        }
        if (!array_key_exists('category', $data)) {
            throw new InvalidTask('category_invalid', 'category', 'La catégorie est obligatoire (Pro ou Perso).');
        }

        return $this->check($state, $data);
    }

    /**
     * Valide les changements puis l'état fusionné.
     *
     * @param array<string,mixed> $current état actuel (forme `Task`, au moins les six champs)
     * @param array<string,mixed> $changes
     * @return array{title:string,category:string,due_date:?string,recurrence:?array{interval:int,unit:string},estimated_minutes:?int,description:?string,project:?string,conclusions:?string}
     * @throws InvalidTask
     */
    public function validateUpdate(array $current, array $changes): array
    {
        $this->rejectUnknownKeys($changes);
        $state = [];
        foreach (self::FIELDS as $f) {
            $state[$f] = $current[$f];
        }

        return $this->check($state, $changes);
    }

    /**
     * Valeur d'état demandée : non-chaîne ou null -> field_type_invalid; chaîne inconnue ou vide -> status_invalid.
     *
     * @throws InvalidTask
     */
    public function validateStatus(mixed $value): TaskStatus
    {
        if (!is_string($value)) {
            throw new InvalidTask('field_type_invalid', 'status', 'Type de valeur incorrect.');
        }

        return TaskStatus::tryFrom($value)
            ?? throw new InvalidTask('status_invalid', 'status', 'L\'état doit être todo, in_progress ou done.');
    }

    /**
     * Filtres de liste : clés permises `status`, `category` et `project`, une seule valeur chacun.
     *
     * @param array<string,mixed> $filters
     * @return array{status?:TaskStatus,category?:string,project?:string}
     * @throws InvalidTask
     */
    public function validateFilters(array $filters): array
    {
        foreach (array_keys($filters) as $key) {
            if ($key !== 'status' && $key !== 'category' && $key !== 'project') {
                throw new InvalidTask('field_unknown', (string) $key, 'Filtre inconnu.');
            }
        }
        $out = [];
        if (array_key_exists('status', $filters)) {
            $out['status'] = $this->validateStatus($filters['status']);
        }
        if (array_key_exists('category', $filters)) {
            $out['category'] = $this->category($filters['category']);
        }
        if (array_key_exists('project', $filters)) {
            $out['project'] = $this->requiredProject($filters['project']);
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private function rejectUnknownKeys(array $data): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                throw new InvalidTask('field_unknown', (string) $key, 'Champ inconnu.');
            }
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function check(array $state, array $changes): array
    {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $value = $changes[$field];
            $state[$field] = match ($field) {
                'title' => $this->title($value),
                'category' => $this->category($value),
                'due_date' => $this->dueDate($value),
                'recurrence' => $this->recurrence($value),
                'estimated_minutes' => $this->estimatedMinutes($value),
                'description' => $this->description($value),
                'project' => $this->project($value),
                'conclusions' => $value === null ? null : $this->text($value, 'conclusions'),
            };
        }
        if ($state['recurrence'] !== null && $state['due_date'] === null) {
            throw new InvalidTask('recurrence_requires_due_date', 'recurrence', 'Une tâche périodique exige une date butée.');
        }

        return $state;
    }

    private function text(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidTask('field_type_invalid', $field, 'Type de valeur incorrect.');
        }
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidTask('text_invalid', $field, 'Texte invalide (UTF-8 sans octet nul attendu).');
        }

        return $value;
    }

    private function title(mixed $value): string
    {
        $title = $this->text($value, 'title');
        if (preg_match('/^[\s\p{Z}]*$/u', $title) === 1) {
            throw new InvalidTask('title_blank', 'title', 'Le titre ne peut pas être vide.');
        }
        if (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            throw new InvalidTask('title_too_long', 'title', 'Le titre dépasse 255 caractères.');
        }

        return $title;
    }

    private function category(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidTask('field_type_invalid', 'category', 'Type de valeur incorrect.');
        }
        if (!in_array($value, self::CATEGORIES, true)) {
            throw new InvalidTask('category_invalid', 'category', 'La catégorie doit être Pro ou Perso.');
        }

        return $value;
    }

    private function dueDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidTask('field_type_invalid', 'due_date', 'Type de valeur incorrect.');
        }
        // Date passée autorisée (décision validée).
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1
            || (int) $m[1] < 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidTask('due_date_invalid', 'due_date', 'Date attendue au format AAAA-MM-JJ.');
        }

        return $value;
    }

    /** @return ?array{interval:int,unit:string} */
    private function recurrence(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidTask('field_type_invalid', 'recurrence', 'Type de valeur incorrect.');
        }
        foreach (array_keys($value) as $key) {
            if ($key !== 'interval' && $key !== 'unit') {
                throw new InvalidTask('field_unknown', 'recurrence.' . $key, 'Champ inconnu.');
            }
        }
        if (($value['interval'] ?? null) === null || ($value['unit'] ?? null) === null) {
            throw new InvalidTask('recurrence_incomplete', 'recurrence', 'La périodicité exige un intervalle et une unité.');
        }
        $interval = $value['interval'];
        if (!is_int($interval)) {
            throw new InvalidTask('field_type_invalid', 'recurrence.interval', 'Type de valeur incorrect.');
        }
        if ($interval < 1 || $interval > self::INT4_MAX) {
            throw new InvalidTask('recurrence_interval_invalid', 'recurrence.interval', 'L\'intervalle doit être un entier strictement positif.');
        }
        $unit = $value['unit'];
        if (!is_string($unit)) {
            throw new InvalidTask('field_type_invalid', 'recurrence.unit', 'Type de valeur incorrect.');
        }
        if (!in_array($unit, self::UNITS, true)) {
            throw new InvalidTask('recurrence_unit_invalid', 'recurrence.unit', 'Unité attendue : day, week, month ou year.');
        }

        return ['interval' => $interval, 'unit' => $unit];
    }

    private function estimatedMinutes(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new InvalidTask('field_type_invalid', 'estimated_minutes', 'Type de valeur incorrect.');
        }
        if ($value < 1 || $value > self::INT4_MAX) {
            throw new InvalidTask('estimated_minutes_invalid', 'estimated_minutes', 'L\'estimation doit être un entier strictement positif.');
        }

        return $value;
    }

    private function description(mixed $value): ?string
    {
        return $value === null ? null : $this->text($value, 'description');
    }

    /** `null` ou valeur exacte de PROJECTS (sensible à la casse, sans trim). */
    private function project(mixed $value): ?string
    {
        return $value === null ? null : $this->requiredProject($value);
    }

    private function requiredProject(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidTask('field_type_invalid', 'project', 'Type de valeur incorrect.');
        }
        if (!in_array($value, self::PROJECTS, true)) {
            throw new InvalidTask('project_invalid', 'project', 'Le projet doit être ' . self::projectList() . '.');
        }

        return $value;
    }

    private static function projectList(): string
    {
        $last = self::PROJECTS[array_key_last(self::PROJECTS)];

        return implode(', ', array_slice(self::PROJECTS, 0, -1)) . ' ou ' . $last;
    }
}
