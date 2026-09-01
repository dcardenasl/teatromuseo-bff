<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Support\JsonArrayAggregateSql;
use CodeIgniter\Test\CIUnitTestCase;

final class JsonArrayAggregateSqlTest extends CIUnitTestCase
{
    public function testMariaDb103UsesSelectOnlyCompatibleAggregation(): void
    {
        $parts = JsonArrayAggregateSql::forDriver('MySQLi', '10.3.38-MariaDB-cll-lve');

        $expression = $parts['aggregate'] . '(' . $parts['object'] . "('id', id)" . $parts['suffix'] . ')';

        $this->assertSame("CONCAT('[', GROUP_CONCAT(JSON_OBJECT('id', id) SEPARATOR ','), ']')", $expression);
    }

    public function testMariaDbWithoutVersionUsesTheSafeFallback(): void
    {
        $parts = JsonArrayAggregateSql::forDriver('MySQLi', 'MariaDB');

        $this->assertSame("CONCAT('[', GROUP_CONCAT(JSON_OBJECT('id', id) SEPARATOR ','), ']')", $parts['aggregate'] . '(' . $parts['object'] . "('id', id)" . $parts['suffix'] . ')');
    }

    public function testSqliteKeepsNativeJsonAggregation(): void
    {
        $this->assertSame(
            [
                'aggregate' => 'json_group_array',
                'object' => 'json_object',
                'suffix' => '',
            ],
            JsonArrayAggregateSql::forDriver('SQLite3', '3.45.0'),
        );
    }

    public function testModernMariaDbKeepsJsonArrayAgg(): void
    {
        $this->assertSame(
            [
                'aggregate' => 'JSON_ARRAYAGG',
                'object' => 'JSON_OBJECT',
                'suffix' => '',
            ],
            JsonArrayAggregateSql::forDriver('MySQLi', '10.6.18-MariaDB'),
        );
    }
}
