<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Repository\Contracts\TransactionManagerInterface;

/**
 * Test double for unit tests: InMemory repositories have no real atomicity
 * to coordinate, so this just runs the callback directly. Real transactional
 * behaviour against MySQL is covered by the integration tests instead.
 */
final class NullTransactionManager implements TransactionManagerInterface
{
    public function transactional(callable $callback): mixed
    {
        return $callback();
    }
}
