<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Validation pure (sans base), fail-fast, des champs d'un projet. Valeurs conservées telles que fournies (aucun trim).
 * L'unicité du nom est arbitrée par la base (ProjectRepository).
 */
final class ProjectValidator
{
    private const FIELDS = ['name', 'category'];
    private const NAME_MAX = 255;

    /**
     * @param array<string,mixed> $data
     * @return array{name:string,category:string}
     * @throws InvalidProject
     */
    public function validateCreate(array $data): array
    {
        $this->rejectUnknownKeys($data);
        // Obligatoires : une valeur absente est traitée comme une valeur invalide du champ.
        if (!array_key_exists('name', $data)) {
            throw new InvalidProject('project_name_blank', 'name', 'Le nom est obligatoire.');
        }
        if (!array_key_exists('category', $data)) {
            throw new InvalidProject('category_invalid', 'category', 'La catégorie est obligatoire (Pro ou Perso).');
        }

        return $this->check(['name' => '', 'category' => ''], $data);
    }

    /**
     * Valide les changements (`null` interdit, clé absente = inchangé) et renvoie l'état fusionné.
     *
     * @param array<string,mixed> $current projet actuel (au moins name et category)
     * @param array<string,mixed> $changes
     * @return array{name:string,category:string}
     * @throws InvalidProject
     */
    public function validateUpdate(array $current, array $changes): array
    {
        $this->rejectUnknownKeys($changes);

        return $this->check(['name' => (string) $current['name'], 'category' => (string) $current['category']], $changes);
    }

    /** @param array<string,mixed> $data */
    private function rejectUnknownKeys(array $data): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                throw new InvalidProject('field_unknown', (string) $key, 'Champ inconnu.');
            }
        }
    }

    /**
     * @param array{name:string,category:string} $state
     * @param array<string,mixed> $changes
     * @return array{name:string,category:string}
     */
    private function check(array $state, array $changes): array
    {
        if (array_key_exists('name', $changes)) {
            $state['name'] = $this->name($changes['name']);
        }
        if (array_key_exists('category', $changes)) {
            $state['category'] = $this->category($changes['category']);
        }

        return $state;
    }

    private function name(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidProject('field_type_invalid', 'name', 'Type de valeur incorrect.');
        }
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidProject('text_invalid', 'name', 'Texte invalide (UTF-8 sans octet nul attendu).');
        }
        if (preg_match('/^[\s\p{Z}]*$/u', $value) === 1) {
            throw new InvalidProject('project_name_blank', 'name', 'Le nom ne peut pas être vide.');
        }
        if (mb_strlen($value, 'UTF-8') > self::NAME_MAX) {
            throw new InvalidProject('project_name_too_long', 'name', 'Le nom dépasse 255 caractères.');
        }

        return $value;
    }

    private function category(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidProject('field_type_invalid', 'category', 'Type de valeur incorrect.');
        }
        if (!in_array($value, TaskValidator::CATEGORIES, true)) {
            throw new InvalidProject('category_invalid', 'category', 'La catégorie doit être Pro ou Perso.');
        }

        return $value;
    }
}
