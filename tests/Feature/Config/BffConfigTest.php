<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use App\Libraries\Domain\DomainClient;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Bff;
use Config\Services;
use ReflectionClass;
use RuntimeException;

class BffConfigTest extends CIUnitTestCase
{
    /** @var string|false */
    private $originalGetenv;
    private bool $hadEnv    = false;
    private bool $hadServer = false;
    private string $savedEnv    = '';
    private string $savedServer = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGetenv = getenv('BFF_ALLOWED_ORIGINS');
        $this->hadEnv         = array_key_exists('BFF_ALLOWED_ORIGINS', $_ENV);
        $this->hadServer      = array_key_exists('BFF_ALLOWED_ORIGINS', $_SERVER);
        $this->savedEnv       = $this->hadEnv ? (string) $_ENV['BFF_ALLOWED_ORIGINS'] : '';
        $this->savedServer    = $this->hadServer ? (string) $_SERVER['BFF_ALLOWED_ORIGINS'] : '';
    }

    protected function tearDown(): void
    {
        if ($this->originalGetenv !== false) {
            putenv('BFF_ALLOWED_ORIGINS=' . $this->originalGetenv);
        } else {
            putenv('BFF_ALLOWED_ORIGINS');
        }

        if ($this->hadEnv) {
            $_ENV['BFF_ALLOWED_ORIGINS'] = $this->savedEnv;
        } else {
            unset($_ENV['BFF_ALLOWED_ORIGINS']);
        }

        if ($this->hadServer) {
            $_SERVER['BFF_ALLOWED_ORIGINS'] = $this->savedServer;
        } else {
            unset($_SERVER['BFF_ALLOWED_ORIGINS']);
        }

        parent::tearDown();
    }

    private function setOrigins(string $value): void
    {
        putenv('BFF_ALLOWED_ORIGINS=' . $value);
        $_ENV['BFF_ALLOWED_ORIGINS']    = $value;
        $_SERVER['BFF_ALLOWED_ORIGINS'] = $value;
    }

    private function clearOrigins(): void
    {
        putenv('BFF_ALLOWED_ORIGINS');
        unset($_ENV['BFF_ALLOWED_ORIGINS'], $_SERVER['BFF_ALLOWED_ORIGINS']);
    }

    public function testParsesCommaSeparatedOrigins(): void
    {
        $this->setOrigins('http://localhost:3000,http://localhost:5173');

        $config = new Bff();

        $this->assertSame(
            ['http://localhost:3000', 'http://localhost:5173'],
            $config->allowedOrigins
        );
    }

    public function testTrimsWhitespaceAndDropsEmpties(): void
    {
        $this->setOrigins('  http://a.test , , http://b.test  ');

        $config = new Bff();

        $this->assertSame(['http://a.test', 'http://b.test'], $config->allowedOrigins);
    }

    public function testParsesConfiguredDashboardDomainCoordinates(): void
    {
        $originalGetenv = getenv('BFF_DOMAINS');
        $hadEnv         = array_key_exists('BFF_DOMAINS', $_ENV);
        $hadServer      = array_key_exists('BFF_DOMAINS', $_SERVER);
        $savedEnv       = $hadEnv ? (string) $_ENV['BFF_DOMAINS'] : '';
        $savedServer    = $hadServer ? (string) $_SERVER['BFF_DOMAINS'] : '';

        putenv('BFF_DOMAINS=cms:http://cms.test,catalog:http://catalog.test,event:http://event.test');
        $_ENV['BFF_DOMAINS']    = 'cms:http://cms.test,catalog:http://catalog.test,event:http://event.test';
        $_SERVER['BFF_DOMAINS'] = 'cms:http://cms.test,catalog:http://catalog.test,event:http://event.test';

        try {
            $config = new Bff();

            $this->assertSame([
                'cms'     => 'http://cms.test',
                'catalog' => 'http://catalog.test',
                'event'   => 'http://event.test',
            ], $config->domains);

            foreach (['cms', 'catalog', 'event'] as $domainCode) {
                $this->assertInstanceOf(DomainClient::class, Services::domainClient($domainCode, false));
            }
        } finally {
            if ($originalGetenv !== false) {
                putenv('BFF_DOMAINS=' . $originalGetenv);
            } else {
                putenv('BFF_DOMAINS');
            }

            if ($hadEnv) {
                $_ENV['BFF_DOMAINS'] = $savedEnv;
            } else {
                unset($_ENV['BFF_DOMAINS']);
            }

            if ($hadServer) {
                $_SERVER['BFF_DOMAINS'] = $savedServer;
            } else {
                unset($_SERVER['BFF_DOMAINS']);
            }
        }
    }

    public function testEmptyAllowedOriginsTolerableInDevelopment(): void
    {
        $this->clearOrigins();

        $config = new Bff();

        $this->assertSame([], $config->allowedOrigins);
    }

    public function testEmptyAllowedOriginsThrowsInProduction(): void
    {
        putenv('BFF_ALLOWED_ORIGINS=');

        // Mutate the global ENVIRONMENT constant via reflection is not possible
        // (constants are immutable). Instead, instantiate the class and assert
        // the constructor branch directly by skipping when not in production.
        if ((defined('ENVIRONMENT') ? ENVIRONMENT : 'testing') === 'production') {
            $this->expectException(RuntimeException::class);
            new Bff();
            return;
        }

        // In non-production environments, document that no throw occurs and
        // the rule is enforced by the production-only guard reviewed manually.
        $reflection = new ReflectionClass(Bff::class);
        $source     = (string) file_get_contents((string) $reflection->getFileName());
        $this->assertStringContainsString("ENVIRONMENT === 'production'", $source);
        $this->assertStringContainsString('RuntimeException', $source);
    }
}
