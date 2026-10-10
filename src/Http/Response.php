<?php

declare(strict_types=1);

namespace Actions\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /** @param array<string,string> $headers */
    public static function json(int $status, mixed $data, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        header('X-Content-Type-Options: nosniff');
        echo $this->body;
    }
}
