<?php

declare(strict_types=1);

namespace Actions\Infrastructure;

final class Config
{
    public const ENVIRONMENTS = ['local', 'test', 'production'];
    public const DEFAULT_PORTAL_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    /**
     * @param list<string> $mcpAllowedOrigins
     * @param list<string> $portalAllowedHosts noms d'hôte (sans port) acceptés dans `Host` par le portail
     */
    public function __construct(
        public readonly string $appEnv,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly array $mcpAllowedOrigins = [],
        public readonly array $portalAllowedHosts = self::DEFAULT_PORTAL_HOSTS,
    ) {
    }

    /** @param array<string,string> $env */
    public static function fromArray(array $env): self
    {
        $appEnv = $env['APP_ENV'] ?? 'production';
        if (!in_array($appEnv, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('APP_ENV invalide.');
        }
        foreach (['DB_NAME', 'DB_USER'] as $key) {
            if (($env[$key] ?? '') === '') {
                throw new \InvalidArgumentException("Variable de configuration manquante : $key.");
            }
        }
        $port = $env['DB_PORT'] ?? '5432';
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException('DB_PORT invalide.');
        }
        $origins = array_values(array_filter(
            array_map('trim', explode(',', $env['MCP_ALLOWED_ORIGINS'] ?? '')),
            static fn (string $o): bool => $o !== '',
        ));
        // Hôtes du portail : minuscules; variable absente ou vide = défaut (local seulement).
        $hosts = array_values(array_filter(
            array_map(static fn (string $h): string => strtolower(trim($h)), explode(',', $env['PORTAL_ALLOWED_HOSTS'] ?? '')),
            static fn (string $h): bool => $h !== '',
        ));

        return new self(
            $appEnv,
            ($env['DB_HOST'] ?? '') !== '' ? $env['DB_HOST'] : '127.0.0.1',
            (int) $port,
            $env['DB_NAME'],
            $env['DB_USER'],
            $env['DB_PASSWORD'] ?? '',
            $origins,
            $hosts === [] ? self::DEFAULT_PORTAL_HOSTS : $hosts,
        );
    }

    /**
     * Charge l'environnement : les variables déjà définies priment sur le fichier.
     * Fichier : $file, sinon ACTIONS_ENV_FILE, sinon .env à la racine (facultatif).
     */
    public static function load(?string $file = null, ?array $environment = null): self
    {
        $environment ??= self::processEnvironment();
        $file ??= $environment['ACTIONS_ENV_FILE'] ?? dirname(__DIR__, 2) . '/.env';
        $fromFile = [];
        if (is_file($file) && is_readable($file)) {
            $fromFile = EnvFile::parse((string) file_get_contents($file));
        }

        return self::fromArray($environment + $fromFile);
    }

    /** @return array<string,string> */
    public static function processEnvironment(): array
    {
        $out = [];
        foreach (getenv() as $k => $v) {
            if (is_string($v)) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }
}
