<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database stub.
 *
 * The BFF is stateless — it owns no database connection of its own.
 * This class exists only because the CI4 framework (specifically the
 * debug toolbar's Database collector) loads `Config\Database` during
 * bootstrap. The default group points at a no-op SQLite memory database
 * so that calling `db_connect()` does not crash if any third-party
 * library tries to resolve it; in normal request paths nothing on the
 * BFF should ever touch the database connection.
 */
class Database extends Config
{
    /**
     * The default database connection.
     */
    public string $defaultGroup = 'default';

    /**
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'      => '',
        'hostname' => '',
        'username' => '',
        'password' => '',
        'database' => ':memory:',
        'DBDriver' => 'SQLite3',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => false,
        'charset'  => 'utf8',
        'DBCollat' => 'utf8_general_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 0,
        'numberNative' => false,
        'foreignKeys'  => true,
        'busyTimeout'  => 1000,
    ];

    /**
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'      => '',
        'hostname' => '',
        'username' => '',
        'password' => '',
        'database' => ':memory:',
        'DBDriver' => 'SQLite3',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => false,
        'charset'  => 'utf8',
        'DBCollat' => 'utf8_general_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 0,
        'numberNative' => false,
        'foreignKeys'  => true,
        'busyTimeout'  => 1000,
    ];

    public function __construct()
    {
        parent::__construct();
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
