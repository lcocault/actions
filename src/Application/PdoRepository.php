<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Accès PDO commun aux dépôts : connexion paresseuse, requêtes paramétrées, transaction explicite,
 * traduction des pertes de connexion. Les dépôts d'un même cas d'usage reçoivent la même connexion
 * (fermeture partagée créée par Bootstrap), donc la même transaction.
 */
abstract class PdoRepository
{
    private ?\PDO $pdo = null;

    /** @param \Closure():\PDO $connect */
    public function __construct(private readonly \Closure $connect)
    {
    }

    /**
     * Transaction explicite en READ COMMITTED (exigé par le trigger anti-cycle).
     *
     * @template T
     * @param \Closure():T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        $pdo = $this->pdo();
        try {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->unavailableOrSame($e);
        }
    }

    /** @param array<string,mixed> $params */
    protected function run(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (\PDOException $e) {
            throw $this->unavailableOrSame($e);
        }
    }

    protected function pdo(): \PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = ($this->connect)();
            } catch (\PDOException $e) {
                throw new DatabaseUnavailable('Base injoignable.', 0, $e);
            }
        }

        return $this->pdo;
    }

    protected function sqlState(\PDOException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    /** Connexion perdue (classe 08) -> DatabaseUnavailable; sinon l'exception d'origine (erreur interne). */
    protected function unavailableOrSame(\Throwable $e): \Throwable
    {
        if ($e instanceof \PDOException && str_starts_with($this->sqlState($e), '08')) {
            return new DatabaseUnavailable('Base injoignable.', 0, $e);
        }

        return $e;
    }
}
