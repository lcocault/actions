<?php

declare(strict_types=1);

namespace Actions\Portal;

/**
 * Rendu de gabarits PHP natifs (src/Portal/Views). Un seul assistant d'échappement, `$e`, appliqué à toute
 * valeur affichée; `$url` produit un chemin déjà échappé; `$render` insère un partiel (HTML déjà produit
 * par ce même rendu). Un test de garde interdit toute autre sortie dans les gabarits.
 */
final class View
{
    public function __construct(
        private readonly string $directory = __DIR__ . '/Views',
        private readonly string $prefix = '',
    ) {
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        if (preg_match('/^[a-z_]+$/', $template) !== 1) {
            throw new \InvalidArgumentException('Nom de gabarit invalide.');
        }
        $e = static fn (mixed $value): string => self::escape($value);
        $url = fn (string $path, array $query = []): string => self::escape(
            $this->prefix . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)),
        );
        $render = fn (string $partial, array $partialVars = []): string => $this->render($partial, $partialVars);

        return (static function (string $__file, array $__vars, \Closure $e, \Closure $url, \Closure $render): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        })($this->directory . '/' . $template . '.php', $vars, $e, $url, $render);
    }

    /** Chemin de l'application (préfixe éventuel), non échappé : pour un en-tête `Location`. */
    public function path(string $path): string
    {
        return $this->prefix . $path;
    }

    public static function escape(mixed $value): string
    {
        if ($value !== null && !is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('Valeur non affichable.');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
