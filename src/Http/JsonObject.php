<?php

declare(strict_types=1);

namespace Actions\Http;

/** Conversion des objets JSON décodés (`stdClass`) en tableaux associatifs; les listes JSON restent des listes. */
final class JsonObject
{
    public static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::toArray(...), $value);
        }

        return $value;
    }
}
