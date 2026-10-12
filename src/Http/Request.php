<?php

declare(strict_types=1);

namespace Actions\Http;

final class Request
{
    /**
     * @param array<string,string> $headers noms en minuscules
     * @param array<string,mixed> $query paramètres de la chaîne de requête (valeurs chaîne ou tableau)
     * @param array<string,mixed> $form champs d'un formulaire `application/x-www-form-urlencoded` (portail)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $query = [],
        public readonly array $form = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = $value;
            }
        }
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $body = file_get_contents('php://input');
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $query = [];
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        return new self(
            strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            is_string($path) && $path !== '' ? $path : '/',
            $headers,
            $body === false ? '' : $body,
            $query,
            $_POST,
        );
    }
}
