<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Hub;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Http\Client\HubClient;
use RuntimeException;

class HubClientIntrospectTest extends CIUnitTestCase
{
    private function makeConfig(): \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig
    {
        return new \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig(
            url: 'http://hub.test',
            apiKey: 'test-key',
            httpTimeout: 5,
            introspectCacheTtl: 60
        );
    }

    public function testEmptyTokenReturnsInvalid(): void
    {
        $client = new HubClient(
            $this->makeConfig(),
            $this->createMock(CURLRequest::class),
            $this->createMock(CacheInterface::class),
        );

        $result = $client->introspect('');

        $this->assertFalse($result->valid);
        $this->assertSame('invalid_or_expired', $result->error);
    }

    public function testReturnsCachedResultWithoutCallingHub(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn([
            'valid'       => true,
            'uid'         => 42,
            'permissions' => ['users.read'],
            'exp'         => time() + 3600,
        ]);

        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->never())->method('request');

        $client = new HubClient($this->makeConfig(), $http, $cache);
        $result = $client->introspect('abc');

        $this->assertTrue($result->valid);
        $this->assertSame(42, $result->uid);
        $this->assertSame(['users.read'], $result->permissions);
    }

    public function testValidResponseIsCached(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('save');

        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->once())
            ->method('request')
            ->with('POST', $this->stringContains('/api/v1/auth/introspect'))
            ->willReturn($this->jsonResponse(200, [
                'data' => [
                    'valid'       => true,
                    'uid'         => 7,
                    'permissions' => ['users.read', 'users.write'],
                    'exp'         => time() + 1800,
                ],
            ]));

        $client = new HubClient($this->makeConfig(), $http, $cache);
        $result = $client->introspect('valid-token');

        $this->assertTrue($result->valid);
        $this->assertSame(7, $result->uid);
        $this->assertSame(['users.read', 'users.write'], $result->permissions);
    }

    public function testInvalidResponseIsNotCached(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->never())->method('save');

        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($this->jsonResponse(200, [
            'data' => ['valid' => false, 'error' => 'invalid_or_expired'],
        ]));

        $client = new HubClient($this->makeConfig(), $http, $cache);
        $result = $client->introspect('bad-token');

        $this->assertFalse($result->valid);
    }

    public function testHubUnreachableDowngradesToInvalid(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->never())->method('save');

        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willThrowException(new RuntimeException('connection refused'));

        $client = new HubClient($this->makeConfig(), $http, $cache);
        $result = $client->introspect('anything');

        $this->assertFalse($result->valid);
        $this->assertSame('hub_unreachable', $result->error);
    }

    public function testHubReturning4xxDowngradesToInvalid(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);

        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($this->jsonResponse(401, ['message' => 'bad app key']));

        $client = new HubClient($this->makeConfig(), $http, $cache);
        $result = $client->introspect('anything');

        $this->assertFalse($result->valid);
        $this->assertSame('hub_unreachable', $result->error);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }
}
