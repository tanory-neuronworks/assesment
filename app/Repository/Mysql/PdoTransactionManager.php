<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Repository\Contracts\TransactionManagerInterface;
use PDO;
use Throwable;

final class PdoTransactionManager implements TransactionManagerInterface
{
    private int $savepointCounter = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Uses a real transaction normally. If a transaction is already open
     * (e.g. an integration test wrapping the whole test in one rolled-back
     * transaction for isolation) it nests via SAVEPOINT instead, since PDO
     * itself does not support nested beginTransaction() calls.
     */
    public function transactional(callable $callback): mixed
    {
        $nested = $this->pdo->inTransaction();
        $savepoint = 'sp_' . (++$this->savepointCounter);

        if ($nested) {
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        } else {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if ($nested) {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($nested) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
