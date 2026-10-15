<?php

declare(strict_types=1);

namespace Actions\Rest;

use Actions\Http\JsonObject;
use Actions\Http\Request;

/** Corps de requête REST = objet JSON (forme du transport seulement; types et règles relèvent des validateurs). */
final class JsonBody
{
    /**
     * Retourne null si le corps est vide, invalide, trop profond ou n'est pas un objet.
     *
     * @return array<string,mixed>|null
     */
    public static function object(Request $request): ?array
    {
        try {
            $decoded = json_decode($request->body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!$decoded instanceof \stdClass) {
            return null;
        }
        $data = JsonObject::toArray($decoded);

        return is_array($data) ? $data : null;
    }

    public static function invalid(): \Actions\Http\Response
    {
        return RestAdapter::error(400, 'invalid_json', 'Le corps doit être un objet JSON.');
    }
}
