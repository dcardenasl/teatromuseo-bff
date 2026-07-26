<?php

declare(strict_types=1);

namespace Tests\Feature\Filters;

use CodeIgniter\Config\Factories;
use Config\Services;
use Tests\Support\ApiTestCase;

/**
 * BFF-105: the throttle filter sits in `Config\Filters::$globals.before` by
 * default. Every request is rate-limited per-IP. The orchestrator probes
 * (`/ping`, `/live`, `/ready`) are exempt — they fire every few seconds and
 * would otherwise self-exhaust the bucket.
 */
class GlobalThrottleTest extends ApiTestCase
{
    private const LIMIT = 3;

    protected function setUp(): void
    {
        parent::setUp();
        // Squeeze the limit so a handful of requests is enough to trip the
        // throttle. Reset the singletons so the new env is picked up.
        putenv('RATE_LIMIT_REQUESTS=' . self::LIMIT);
        putenv('RATE_LIMIT_WINDOW=60');
        Factories::reset();
        Services::reset(false);
    }

    protected function tearDown(): void
    {
        putenv('RATE_LIMIT_REQUESTS');
        putenv('RATE_LIMIT_WINDOW');
        Factories::reset();
        Services::reset(false);
        parent::tearDown();
    }

    public function testNormalRouteIsThrottledAfterLimit(): void
    {
        for ($i = 1; $i <= self::LIMIT; $i++) {
            $result = $this->get('/api/versions');
            $result->assertStatus(200);
        }

        $blocked = $this->get('/api/versions');
        $blocked->assertStatus(429);
    }

    public function testPingIsExemptFromThrottle(): void
    {
        // Burn through twice the limit on /ping — should never be blocked.
        for ($i = 1; $i <= self::LIMIT * 2; $i++) {
            $result = $this->get('/ping');
            $result->assertStatus(200);
        }
    }

    public function testLiveIsExemptFromThrottle(): void
    {
        for ($i = 1; $i <= self::LIMIT * 2; $i++) {
            $result = $this->get('/live');
            $result->assertStatus(200);
        }
    }

    public function testReadyIsExemptFromThrottle(): void
    {
        // /ready is behind `featureToggle:monitoring`. Enable it for this test.
        putenv('MONITORING_ENABLED=true');
        Factories::reset();

        // Force a hub-up response so /ready returns 200 (not 503 from a probe miss).
        $response = $this->createMock(\CodeIgniter\HTTP\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('');
        $http = $this->createMock(\CodeIgniter\HTTP\CURLRequest::class);
        $http->method('request')->willReturn($response);
        Services::injectMock('curlrequest', $http);
        putenv('bff.hubUrl=http://hub.test');
        Services::resetSingle('bff');

        for ($i = 1; $i <= self::LIMIT * 2; $i++) {
            $result = $this->get('/ready');
            $result->assertStatus(200);
        }

        putenv('MONITORING_ENABLED');
        putenv('bff.hubUrl');
    }
}
