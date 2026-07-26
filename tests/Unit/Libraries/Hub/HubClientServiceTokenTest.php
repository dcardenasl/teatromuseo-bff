<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Hub;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Exceptions\ServiceUnavailableException;
use dcardenasl\Ci4ApiCore\Http\Client\HubClient;

class HubClientServiceTokenTest extends CIUnitTestCase
{
    private function makeConfig(int $safetyMargin = 30): \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig
    {
        return new \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig(
            url: 'http://hub.test',
            apiKey: 'test-key',
            serviceTokenSafetyMargin: $safetyMargin,
            httpTimeout: 5
        );
    }

    public function testReturnsCachedTokenWhenWellWithinExpiry(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn([
            'access_token' => 'cached-token',
            'expires_at'   => time() + 3600,
        ]);

        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->never())->method('request');

        $client = new HubClient($this->makeConfig(), $http, $cache);

        $this->assertSame('cached-token', $client->getServiceToken());
    }

    public function testRefreshesWhenCachedTokenIsCloseToExpiry(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn([
            'access_token' => 'about-to-expire',
            'expires_at'   => time() + 10, // < safetyMargin of 30
        ]);
        $cache->expects($this->once())->method('save');

        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->once())
            ->method('request')
            ->with('POST', $this->stringContains('/api/v1/auth/service-token'))
            ->willReturn($this->jsonResponse(200, [
                'data' => ['access_token' => 'fresh-token', 'expires_in' => 3600],
            ]));

        $client = new HubClient($this->makeConfig(30), $http, $cache);

        $this->assertSame('fresh-token', $client->getServiceToken());
    }

    public function testRefreshesWhenNothingCached(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('save');

        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturn($this->jsonResponse(200, [
                'data' => ['access_token' => 'first-token', 'expires_in' => 1800],
            ]));

        $client = new HubClient($this->makeConfig(), $http, $cache);

        $this->assertSame('first-token', $client->getServiceToken());
    }

    public function testThrowsServiceUnavailableOn5xx(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);

        // AbstractServiceClient retries once on 5xx; mock two consecutive failures.
        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->exactly(2))
            ->method('request')
            ->willReturn($this->jsonResponse(500, ['message' => 'upstream broken']));

        $client = new HubClient($this->makeConfig(), $http, $cache);

        $this->expectException(ServiceUnavailableException::class);
        $client->getServiceToken();
    }

    public function testThrowsServiceUnavailableOnMalformedPayload(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);

        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = new HubClient($this->makeConfig(), $http, $cache);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('malformed service-token payload');
        $client->getServiceToken();
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
