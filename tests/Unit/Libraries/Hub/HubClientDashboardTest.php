<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Hub;

use App\Libraries\Hub\HubClient;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

final class HubClientDashboardTest extends CIUnitTestCase
{
    public function testGetReadsHubJsonWithBearerToken(): void
    {
        $capturedOptions = null;
        $response        = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode([
            'data' => ['sections' => ['users' => ['total' => 4]]],
        ], JSON_THROW_ON_ERROR));

        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedOptions, $response): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertSame('http://hub.test/api/v1/admin/dashboard/summary', $url);
                $capturedOptions = $options;

                return $response;
            });

        $config = new \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig(
            url: 'http://hub.test',
            apiKey: 'test-key',
            httpTimeout: 5,
        );
        $client = new HubClient($config, $http, $this->createMock(CacheInterface::class));

        $result = $client->get('/api/v1/admin/dashboard/summary', 'visitor-token');

        $this->assertSame(['sections' => ['users' => ['total' => 4]]], $result);
        $this->assertSame('Bearer visitor-token', $capturedOptions['headers']['Authorization']);
        $this->assertSame('application/json', $capturedOptions['headers']['Accept']);
    }
}
