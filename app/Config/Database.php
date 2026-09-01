<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database configuration.
 *
 * The BFF owns no writable database connection of its own.
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
     * The default database connection. It intentionally remains the in-memory
     * SQLite compatibility group; all production connections are explicit
     * named read-only groups consumed only by app/PublicRead and app/AdminRead.
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

    /** @var array<string, mixed> */
    public array $cms_readonly = [];

    /** @var array<string, mixed> */
    public array $catalog_readonly = [];

    /** @var array<string, mixed> */
    public array $event_readonly = [];

    /** @var array<string, mixed> */
    public array $hub_readonly = [];

    public function __construct()
    {
        parent::__construct();
        $this->cms_readonly = self::mysqlReadOnlyConfig('CMS_READONLY');
        $this->catalog_readonly = self::mysqlReadOnlyConfig('CATALOG_READONLY');
        $this->event_readonly = self::mysqlReadOnlyConfig('EVENT_READONLY');
        $this->hub_readonly = self::mysqlReadOnlyConfig('HUB_READONLY');
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }

    /** @return array<string, mixed> */
    private static function mysqlReadOnlyConfig(string $prefix): array
    {
        return [
            'DSN' => '',
            'hostname' => (string) env($prefix . '_DB_HOSTNAME', ''),
            'username' => (string) env($prefix . '_DB_USERNAME', ''),
            'password' => (string) env($prefix . '_DB_PASSWORD', ''),
            'database' => (string) env($prefix . '_DB_DATABASE', ''),
            'DBDriver' => (string) env($prefix . '_DB_DRIVER', 'MySQLi'),
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => false,
            'charset' => 'utf8mb4',
            'DBCollat' => 'utf8mb4_unicode_ci',
            'swapPre' => '',
            'encrypt' => false,
            'compress' => false,
            'strictOn' => true,
            'failover' => [],
            'port' => (int) env($prefix . '_DB_PORT', '3306'),
            // Match the public domain contract: MySQL scalar values that are
            // not explicitly normalized by a reader remain strings. This is
            // required for byte-for-byte parity on nested occurrence fields.
            'numberNative' => false,
        ];
    }
}
