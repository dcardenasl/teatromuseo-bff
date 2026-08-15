<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\System;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Tests\Support\ApiTestCase;

/**
 * BFF-104: liveness/readiness/health probes target the hub, not a DB. The BFF
 * is stateless so `ready` and `health` must reflect the BFF's ability to
 * actually serve clients (hub reachable) rather than a non-existent database.
 */
class HealthControllerTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MONITORING_ENABLED=true');
        // The probe targets Config\Bff::$hubUrl; pin it to a deterministic
        // value so the mocked CURLRequest matches what the controller calls.
        putenv('bff.hubUrl=http://hub.test');
        Services::resetSingle('bff');
    }

    protected function tearDown(): void
    {
        putenv('MONITORING_ENABLED');
        putenv('bff.hubUrl');
        parent::tearDown();
    }

    public function testLiveEndpointReturnsAlive(): void
    {
        $result = $this->get('/live');

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('alive', $json['status']);
        $this->assertArrayHasKey('uptime_seconds', $json);
    }

    public function testPingEndpointReturnsOk(): void
    {
        $result = $this->get('/ping');

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('ok', $json['status']);
    }

    public function testReadyReturns200WhenHubIsReachable(): void
    {
        $this->mockHubPing(200);

        $result = $this->get('/ready');

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('ready', $json['status']);
        $this->assertSame('healthy', $json['hub']['status']);
    }

    public function testReadyReturns503WhenHubIsUnreachable(): void
    {
        $this->mockHubFailing();

        $result = $this->get('/ready');

        $result->assertStatus(503);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('not_ready', $json['status']);
        $this->assertSame('unhealthy', $json['hub']['status']);
    }

    public function testReadyReturns503WhenHubResponds5xx(): void
    {
        $this->mockHubPing(503);

        $result = $this->get('/ready');

        $result->assertStatus(503);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('not_ready', $json['status']);
        $this->assertSame(503, $json['hub']['hub_status_code']);
    }

    public function testHealthAggregatesHubAndLocalChecks(): void
    {
        $this->mockHubPing(200);

        $result = $this->get('/health');

        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertArrayHasKey('hub', $json['checks']);
        $this->assertArrayHasKey('disk', $json['checks']);
        $this->assertArrayHasKey('writable', $json['checks']);
        $this->assertArrayNotHasKey('database', $json['checks']);
    }

    public function testVersionedHealthAliasUsesTheSameContract(): void
    {
        $this->mockHubPing(200);

        $result = $this->get('/api/v1/health');

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertContains($json['status'], ['healthy', 'degraded']);
        $this->assertArrayHasKey('hub', $json['checks']);
        $this->assertArrayHasKey('databases', $json['checks']);
    }

    public function testHealthEndpointReturns503WhenMonitoringDisabled(): void
    {
        putenv('MONITORING_ENABLED=false');

        $result = $this->get('/health');
        $result->assertStatus(503);
    }

    private function mockHubPing(int $status): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn('');

        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($response);

        Services::injectMock('curlrequest', $http);
    }

    private function mockHubFailing(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willThrowException(new RuntimeException('connection refused'));

        Services::injectMock('curlrequest', $http);
    }
}
