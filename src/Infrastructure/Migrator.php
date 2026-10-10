<?php

declare(strict_types=1);

namespace Actions\Infrastructure;

/** Exécuteur de migrations SQL NNNN_nom.sql, sans « down ». */
final class Migrator
{
    private const LOCK_KEY = 726_000_001;

    public function __construct(private readonly \PDO $pdo, private readonly string $directory)
    {
    }

    /**
     * @return list<string> versions appliquées par cet appel
     * @throws \RuntimeException checksum modifié, fichier manquant, nom invalide, échec SQL
     */
    public function migrate(): array
    {
        $this->lock();
        try {
            $this->ensureTable();
            $files = $this->files();
            $applied = $this->applied();
            $this->assertConsistent($files, $applied);
            $done = [];
            foreach ($files as $version => $path) {
                if (isset($applied[$version])) {
                    continue;
                }
                $sql = (string) file_get_contents($path);
                $this->pdo->beginTransaction();
                try {
                    $this->pdo->exec($sql);
                    $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
                    $stmt->execute([$version, hash('sha256', $sql)]);
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw new \RuntimeException("Échec de la migration $version : " . $e->getMessage(), 0, $e);
                }
                $done[] = $version;
            }

            return $done;
        } finally {
            $this->unlock();
        }
    }

    /** @return array{applied:list<string>,pending:list<string>} */
    public function status(): array
    {
        $this->ensureTable();
        $files = $this->files();
        $applied = $this->applied();
        $this->assertConsistent($files, $applied);

        return [
            'applied' => array_keys($applied),
            'pending' => array_values(array_diff(array_keys($files), array_keys($applied))),
        ];
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version text PRIMARY KEY, checksum text NOT NULL, applied_at timestamptz NOT NULL DEFAULT now())'
        );
    }

    /** @return array<string,string> version => chemin, trié */
    private function files(): array
    {
        $out = [];
        foreach (glob(rtrim($this->directory, '/') . '/*.sql') ?: [] as $path) {
            $name = basename($path, '.sql');
            if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $name)) {
                throw new \RuntimeException("Nom de migration invalide : $name.sql (attendu NNNN_nom.sql).");
            }
            $out[$name] = $path;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return array<string,string> version => checksum */
    private function applied(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version') as $row) {
            $out[$row['version']] = $row['checksum'];
        }

        return $out;
    }

    /** @param array<string,string> $files @param array<string,string> $applied */
    private function assertConsistent(array $files, array $applied): void
    {
        foreach ($applied as $version => $checksum) {
            if (!isset($files[$version])) {
                throw new \RuntimeException("Migration appliquée sans fichier : $version.");
            }
            if (!hash_equals($checksum, hash('sha256', (string) file_get_contents($files[$version])))) {
                throw new \RuntimeException("Migration déjà appliquée modifiée : $version. Corriger par une nouvelle migration.");
            }
        }
    }

    private function lock(): void
    {
        $this->pdo->query('SELECT pg_advisory_lock(' . self::LOCK_KEY . ')')->fetchAll();
    }

    private function unlock(): void
    {
        $this->pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')')->fetchAll();
    }
}
