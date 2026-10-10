<?php

declare(strict_types=1);

namespace Actions\Infrastructure;

/** Lecteur minimal de fichier KEY=VALUE (commentaires #, guillemets simples ou doubles). */
final class EnvFile
{
    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $out = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $value = $m[2];
            if (preg_match('/^"(.*)"\s*(?:#.*)?$/', $value, $q) || preg_match("/^'(.*)'\\s*(?:#.*)?$/", $value, $q)) {
                $value = $q[1];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            $out[$m[1]] = $value;
        }

        return $out;
    }
}
