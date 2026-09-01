<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Cors;
use RuntimeException;

/**
 * Regression: a literal '*' in `BFF_ALLOWED_ORIGINS` combined with
 * `CORS_SUPPORTS_CREDENTIALS=true` used to be accepted silently. The vendor
 * `CorsFilter::isOriginAllowed()` treats '*' as a wildcard match for any
 * origin, so that combination would reflect
 * `Access-Control-Allow-Origin: <any origin>` together with
 * `Access-Control-Allow-Credentials: true` — the classic CORS
 * misconfiguration that lets any site read authenticated responses on the
 * visitor's behalf. `Config\Cors` now throws for this combination in every
 * environment, unlike the empty-allow-list guard in `Config\Bff`, which is
 * production-only.
 */
final class CorsConfigTest extends CIUnitTestCase
{
    /** @var array<string, array{had: bool, value: string}> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = [
            'BFF_ALLOWED_ORIGINS' => $this->snapshot('BFF_ALLOWED_ORIGINS'),
            'CORS_SUPPORTS_CREDENTIALS' => $this->snapshot('CORS_SUPPORTS_CREDENTIALS'),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $snapshot) {
            $this->restore($name, $snapshot);
        }
        parent::tearDown();
    }

    public function testThrowsWhenWildcardOriginIsCombinedWithCredentials(): void
    {
        $this->setEnv('BFF_ALLOWED_ORIGINS', '*');
        $this->setEnv('CORS_SUPPORTS_CREDENTIALS', 'true');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be combined with credentialed CORS');

        new Cors();
    }

    public function testAllowsWildcardOriginWithoutCredentials(): void
    {
        $this->setEnv('BFF_ALLOWED_ORIGINS', '*');
        $this->setEnv('CORS_SUPPORTS_CREDENTIALS', 'false');

        $config = new Cors();

        $this->assertSame(['*'], $config->default['allowedOrigins']);
        $this->assertFalse($config->default['supportsCredentials']);
    }

    public function testAllowsCredentialsWithExplicitOriginList(): void
    {
        $this->setEnv('BFF_ALLOWED_ORIGINS', 'http://localhost:3000');
        $this->setEnv('CORS_SUPPORTS_CREDENTIALS', 'true');

        $config = new Cors();

        $this->assertSame(['http://localhost:3000'], $config->default['allowedOrigins']);
        $this->assertTrue($config->default['supportsCredentials']);
    }

    /** @return array{had: bool, value: string} */
    private function snapshot(string $name): array
    {
        $had = array_key_exists($name, $_ENV);

        return ['had' => $had, 'value' => $had ? (string) $_ENV[$name] : ''];
    }

    /** @param array{had: bool, value: string} $snapshot */
    private function restore(string $name, array $snapshot): void
    {
        if ($snapshot['had']) {
            putenv($name . '=' . $snapshot['value']);
            $_ENV[$name] = $snapshot['value'];
            $_SERVER[$name] = $snapshot['value'];

            return;
        }

        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
