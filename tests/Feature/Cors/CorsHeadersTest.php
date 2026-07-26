<?php

declare(strict_types=1);

namespace Tests\Feature\Cors;

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
    protected function setUp(): void
    {
        parent::setUp();
        putenv('BFF_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000');
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
