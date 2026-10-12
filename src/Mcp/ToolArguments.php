<?php

declare(strict_types=1);

namespace Actions\Mcp;

use Actions\Application\InvalidTask;

/** Forme des arguments d'un appel d'outil seulement (clés, identifiants, confirmation). Aucune règle métier. */
final class ToolArguments
{
    /**
     * @param array<string,mixed> $arguments
     * @param list<string> $allowed
     * @throws InvalidTask field_unknown, même code et mêmes détails que le validateur du domaine
     */
    public static function rejectUnknownKeys(array $arguments, array $allowed): void
    {
        foreach (array_keys($arguments) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidTask('field_unknown', (string) $key, 'Champ inconnu.');
            }
        }
    }

    /**
     * Entier JSON positif : `1.0`, `"5"`, `0`, `-1`, les nombres hors entier PHP (décodés en float) et l'absence sont refusés.
     *
     * @param array<string,mixed> $arguments
     * @throws InvalidToolCall
     */
    public static function id(array $arguments, string $field): int
    {
        $value = $arguments[$field] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new InvalidToolCall('argument_invalid', $field, 'L\'identifiant doit être un entier positif.');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $arguments
     * @throws InvalidToolCall
     */
    public static function requireConfirmation(array $arguments): void
    {
        if (($arguments['confirm'] ?? null) !== true) {
            throw new InvalidToolCall('confirmation_required', 'confirm', 'Suppression non confirmée : renvoyez confirm: true après l\'accord de l\'utilisateur.');
        }
    }
}
