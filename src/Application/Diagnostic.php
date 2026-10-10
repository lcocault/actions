<?php

declare(strict_types=1);

namespace Actions\Application;

/** Seul cas d'usage du squelette, partagé par REST, MCP et portail. */
final class Diagnostic
{
    /** @param \Closure():\PDO $connect */
    public function __construct(private readonly \Closure $connect)
    {
    }

    /** @return array{status:string,service:string} */
    public function ping(): array
    {
        return ['status' => 'ok', 'service' => 'actions'];
    }

    /**
     * @return array{status:string,database:string}
     * @throws DatabaseUnavailable
     */
    public function health(): array
    {
        $ping = $this->ping();
        try {
            $pdo = ($this->connect)();
            $value = $pdo->query('SELECT 1')->fetchColumn();
            if ((int) $value !== 1) {
                throw new DatabaseUnavailable('Réponse inattendue de la base.');
            }
        } catch (\PDOException $e) {
            throw new DatabaseUnavailable('Base injoignable.', 0, $e);
        }

        return ['status' => $ping['status'], 'database' => 'ok'];
    }
}
