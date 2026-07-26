<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Hub;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use dcardenasl\Ci4ApiCore\Http\Client\HubClient;

class HubClientRegisterPermissionTest extends CIUnitTestCase
{
    private function makeConfig(): \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig
    {
        return new \dcardenasl\Ci4ApiCore\Http\Client\HubClientConfig(
            url: 'http://hub.test',
            apiKey: 'test-key',
            httpTimeout: 5
        );
    }

    /**
     * @return array{code: string, resource: string, action: string, description: string}
     */
    private function samplePermission(): array
    {
        return [
            'code'        => 'users.read',
            'resource'    => 'users',
            'action'      => 'read',
            'description' => 'Read users',
        ];
    }

    public function testReturnsTrueOnCreated(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->once())
            ->method('request')
            ->with('POST', $this->stringContains('/api/v1/iam/permissions'))
            ->willReturn($this->jsonResponse(201, ['data' => ['id' => 1]]));

        $client = new HubClient($this->makeConfig(), $http, $this->createMock(CacheInterface::class));

        $this->assertTrue($client->registerPermission($this->samplePermission(), 'admin-token'));
    }

    public function testReturnsFalseOnConflict(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($this->jsonResponse(409, ['message' => 'already exists']));

        $client = new HubClient($this->makeConfig(), $http, $this->createMock(CacheInterface::class));

        $this->assertFalse($client->registerPermission($this->samplePermission(), 'admin-token'));
    }

    public function testReturnsFalseOn422DuplicateValidation(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($this->jsonResponse(422, [
            'message' => 'invalid',
            'errors'  => ['code' => 'already taken'],
        ]));

        $client = new HubClient($this->makeConfig(), $http, $this->createMock(CacheInterface::class));

        $this->assertFalse($client->registerPermission($this->samplePermission(), 'admin-token'));
    }

    public function testPropagatesAuthenticationExceptionOn401(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($this->jsonResponse(401, ['message' => 'no token']));

        $client = new HubClient($this->makeConfig(), $http, $this->createMock(CacheInterface::class));

        $this->expectException(AuthenticationException::class);
        $client->registerPermission($this->samplePermission(), 'expired');
    }

    public function testPropagatesAuthorizationExceptionOn403(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($this->jsonResponse(403, [
            'message' => 'missing iam.superadmin-access',
        ]));

        $client = new HubClient($this->makeConfig(), $http, $this->createMock(CacheInterface::class));

        $this->expectException(AuthorizationException::class);
        $client->registerPermission($this->samplePermission(), 'underprivileged');
    }

    public function testSendsBearerTokenAndAppKeyHeaders(): void
    {
        $captured = null;
        $http     = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$captured): ResponseInterface {
                $captured = $options;

                return $this->jsonResponse(201, ['data' => ['id' => 1]]);
            },
        );

        $client = new HubClient($this->makeConfig(), $http, $this->createMock(CacheInterface::class));
        $client->registerPermission($this->samplePermission(), 'admin-token');

        $this->assertSame('test-key', $captured['headers']['X-App-Key']);
        $this->assertSame('Bearer admin-token', $captured['headers']['Authorization']);
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
