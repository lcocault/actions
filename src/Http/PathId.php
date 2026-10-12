<?php

declare(strict_types=1);

namespace Actions\Http;

/** Identifiant de chemin : entier positif sans zéro initial tenant dans un entier PHP. */
final class PathId
{
    public static function parse(string $segment): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/', $segment) !== 1) {
            return null;
        }
        $id = filter_var($segment, FILTER_VALIDATE_INT);

        return is_int($id) ? $id : null;
    }
}
