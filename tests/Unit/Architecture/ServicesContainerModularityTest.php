<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use CodeIgniter\Test\CIUnitTestCase;

class ServicesContainerModularityTest extends CIUnitTestCase
{
    public function testServicesClassDelegatesCoreFactoryToTrait(): void
    {
        $path   = rtrim((string) ROOTPATH, DIRECTORY_SEPARATOR) . '/app/Config/Services.php';
        $source = file_get_contents($path);

        $this->assertIsString($source);

        // Only the ApiCore trait should be wired — the BFF has no domain
        // factories (no audit chain, no repositories, no domain services).
        $this->assertStringContainsString('use ApiCoreServices;', $source);
        $this->assertStringNotContainsString('use ExampleDomainServices;', $source);
        $this->assertStringNotContainsString('use SystemMonitoringServices;', $source);
        $this->assertStringNotContainsString('use RepositoryModelServices;', $source);

        // The Services class itself must only expose hubClient + request overrides.
        $this->assertStringNotContainsString('public static function auditService(', $source);
        $this->assertStringNotContainsString('public static function itemService(', $source);
    }
}
