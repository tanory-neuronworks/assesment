<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

interface TransactionManagerInterface
{
    /**
     * Runs $callback inside one atomic transaction. On any Throwable the
     * transaction is rolled back and the exception is rethrown; otherwise
     * it's committed and the callback's return value is passed through.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
