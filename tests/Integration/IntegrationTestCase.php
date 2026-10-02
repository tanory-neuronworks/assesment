<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connect([
            'host' => (string) (\EnvLoader::get('TEST_DB_HOST') ?? \EnvLoader::get('DB_HOST', 'mysql')),
            'port' => (string) (\EnvLoader::get('TEST_DB_PORT') ?? \EnvLoader::get('DB_PORT', '3306')),
            'name' => (string) (\EnvLoader::get('TEST_DB_NAME') ?? \EnvLoader::get('DB_NAME', 'inventory')),
            'user' => (string) (\EnvLoader::get('TEST_DB_USER') ?? \EnvLoader::get('DB_USER', 'inventory_user')),
            'pass' => (string) (\EnvLoader::get('TEST_DB_PASS') ?? \EnvLoader::get('DB_PASS', '')),
        ]);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
