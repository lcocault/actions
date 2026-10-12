<?php

declare(strict_types=1);

namespace Actions\Http;

use Actions\Application\DomainError;

/** Corps d'erreur commun à REST et MCP : {"error":{code,message,details?}}, `details` absent s'il est vide. */
final class ErrorBody
{
    /**
     * @param array<string,mixed> $details
     * @return array{error:array<string,mixed>}
     */
    public static function of(string $code, string $message, array $details = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return ['error' => $error];
    }

    /** @return array{error:array<string,mixed>} */
    public static function fromDomain(DomainError $e): array
    {
        return self::of($e->code(), $e->getMessage(), $e->details());
    }
}
