<?php

declare(strict_types=1);

namespace Tests\Feature\Cors;

use CodeIgniter\Config\Factories;
use Tests\Support\ApiTestCase;

/**
 * Verifies the BFF's CORS allow-list behavior on a real (non-preflight)
 * cross-origin request. The CORS filter must echo
 * `Access-Control-Allow-Origin` only for origins listed in
 * `BFF_ALLOWED_ORIGINS`.
 *
 * Preflight (`OPTIONS`) is not exercised here because CI4 routes are
 * method-matched and `/ping` is registered only for GET — preflight
 * coverage is handled at the unit level by `Tests\Feature\Config\BffConfigTest`.
 */
class CorsHeadersTest extends ApiTestCase
{
    private const ALLOWED = 'http://localhost:5173,http://localhost:3000';

    /** @var array{env: string|null, server: string|null} */
    private array $originalOrigins;

    protected function setUp(): void
    {
        parent::setUp();

        // CI4's env() resolves `$_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)`,
        // so a bare putenv() is silently ignored whenever the developer's .env
        // defines BFF_ALLOWED_ORIGINS (it does) — DotEnv has already populated
        // $_ENV by then. Override all three so the test controls the allow-list
        // regardless of the local .env, and restore them in tearDown.
        $this->originalOrigins = [
            'env'    => $_ENV['BFF_ALLOWED_ORIGINS'] ?? null,
            'server' => $_SERVER['BFF_ALLOWED_ORIGINS'] ?? null,
        ];

        $_ENV['BFF_ALLOWED_ORIGINS']    = self::ALLOWED;
        $_SERVER['BFF_ALLOWED_ORIGINS'] = self::ALLOWED;
        putenv('BFF_ALLOWED_ORIGINS=' . self::ALLOWED);

        // Config\Bff parses the allow-list in its constructor and Config\Cors
        // reads Config\Bff in its own; CI4 caches both in Factories. Without
        // this reset the instances built before the override are reused.
        Factories::reset('config');
    }

    protected function tearDown(): void
    {
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
            if ($this->originalOrigins[$key] === null) {
                unset($GLOBALS[$global]['BFF_ALLOWED_ORIGINS']);
            } else {
                $GLOBALS[$global]['BFF_ALLOWED_ORIGINS'] = $this->originalOrigins[$key];
            }
        }

        putenv('BFF_ALLOWED_ORIGINS');
        Factories::reset('config');

        parent::tearDown();
    }

    public function testAllowsOriginInAllowlist(): void
    {
        $result = $this
            ->withHeaders(['Origin' => 'http://localhost:5173'])
            ->get('ping');

        $allow = $result->response()->getHeaderLine('Access-Control-Allow-Origin');

        $this->assertSame(
            'http://localhost:5173',
            $allow,
            'Missing or incorrect Access-Control-Allow-Origin for an allow-listed origin'
        );
    }

    public function testRejectsOriginNotInAllowlist(): void
    {
        $result = $this
            ->withHeaders(['Origin' => 'http://evil.test'])
            ->get('ping');

        $allow = $result->response()->getHeaderLine('Access-Control-Allow-Origin');

        // Safe outcomes: either the BFF omits the header entirely, or it
        // echoes an allow-listed origin (which won't match the requester's
        // Origin, so the browser will block the response).
        $this->assertNotSame(
            'http://evil.test',
            $allow,
            'BFF must NOT echo back an origin that is not in BFF_ALLOWED_ORIGINS'
        );
    }
}
