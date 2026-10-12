<?php

declare(strict_types=1);

namespace Actions\Http;

/** Analyse de l'en-tête `Host` et contrôle contre une liste de noms (protection contre le « DNS rebinding »). */
final class HostGuard
{
    /** @return ?array{name:string,port:?int} nom d'hôte en minuscules (crochets conservés pour IPv6) et port */
    public static function split(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        $header = strtolower($header);
        if ($header[0] === '[') {
            $end = strpos($header, ']');
            if ($end === false) {
                return null;
            }
            $name = substr($header, 0, $end + 1);
            $rest = substr($header, $end + 1);
        } else {
            $pos = strpos($header, ':');
            $name = $pos === false ? $header : substr($header, 0, $pos);
            $rest = $pos === false ? '' : substr($header, $pos);
        }
        if ($name === '' || $name === '[]' || preg_match('/^[a-z0-9.\-\[\]:]+$/', $name) !== 1) {
            return null;
        }
        if ($rest === '') {
            return ['name' => $name, 'port' => null];
        }
        if (preg_match('/^:([0-9]{1,5})$/', $rest, $m) !== 1) {
            return null;
        }

        return ['name' => $name, 'port' => (int) $m[1]];
    }

    /** @param list<string> $allowedNames noms en minuscules, sans port */
    public static function allows(?string $header, array $allowedNames): bool
    {
        $host = self::split($header);

        return $host !== null && in_array($host['name'], $allowedNames, true);
    }
}
