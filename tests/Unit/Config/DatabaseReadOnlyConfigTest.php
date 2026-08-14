<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class DatabaseReadOnlyConfigTest extends CIUnitTestCase
{
    public function testNamedReadConnectionsPreserveThePublicDomainNumericContract(): void
    {
        $config = new Database();

        foreach (['cms_readonly', 'catalog_readonly', 'event_readonly', 'hub_readonly'] as $group) {
            /** @var array<string, mixed> $connection */
            $connection = $config->{$group};

            $this->assertSame('MySQLi', $connection['DBDriver']);
            $this->assertFalse($connection['numberNative']);
            $this->assertTrue($connection['strictOn']);
        }
    }
}
