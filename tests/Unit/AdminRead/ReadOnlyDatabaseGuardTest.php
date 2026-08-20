<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\Support\ReadOnlyDatabaseGuard;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ReadOnlyDatabaseGuardTest extends TestCase
{
    public function testProductionRequiresAllNamedConnectionFields(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("cms_readonly");

        ReadOnlyDatabaseGuard::assertConfigured(
            'cms_readonly',
            [
                'hostname' => 'db.internal',
                'username' => 'readonly',
                'password' => '',
                'database' => 'cms',
                'DBDriver' => 'MySQLi',
            ],
            'production',
        );
    }

    public function testNonProductionMayUseTheCompatibilityConfiguration(): void
    {
        ReadOnlyDatabaseGuard::assertConfigured(
            'cms_readonly',
            [],
            'testing',
        );

        $this->addToAssertionCount(1);
    }

    public function testProductionAcceptsACompleteNamedConnection(): void
    {
        ReadOnlyDatabaseGuard::assertConfigured(
            'hub_readonly',
            [
                'hostname' => 'db.internal',
                'username' => 'readonly',
                'password' => 'secret',
                'database' => 'hub',
                'DBDriver' => 'MySQLi',
            ],
            'production',
        );

        $this->addToAssertionCount(1);
    }
}
