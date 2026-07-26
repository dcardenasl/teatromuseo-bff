<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

/**
 * Pins the canonical proxy contract: `GET /api/v1/users/{id}` forwards to the
 * hub and the upstream response (status + body + headers) flows back unchanged.
 *
 * The test mocks `Services::hubClient()` so no real HTTP is dispatched — only
 * the BFF's controller + router + filter chain is exercised.
 */
final class UsersProxyTest extends ApiTestCase
{
    public function testForwardsSuccessfulUpstreamResponse(): void
    {
        $payload = json_encode([
            'data' => ['id' => 7, 'name' => 'Ada'],
        ], JSON_THROW_ON_ERROR);

        $this->mockCurl(200, $payload);

        $result = $this->get('/api/v1/users/7');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame(['id' => 7, 'name' => 'Ada'], $body['data']);
    }

    public function testPassesThroughUpstream4xxUnchanged(): void
    {
        $this->mockCurl(404, json_encode(['status' => 'error', 'message' => 'not found'], JSON_THROW_ON_ERROR));

        $result = $this->get('/api/v1/users/999');

        $result->assertStatus(404);
        $body = $this->decodeBody($result);
        $this->assertSame('not found', $body['message']);
    }

    public function testForwardsAuthorizationHeaderToUpstream(): void
    {
        $captured = null;
        $this->mockCurl(
            200,
            json_encode(['data' => []], JSON_THROW_ON_ERROR),
            $captured,
        );

        $this->withHeaders([
            'Authorization' => 'Bearer client-token',
        ])->get('/api/v1/users/1');

        $this->assertSame('Bearer client-token', $captured['headers']['Authorization'] ?? null);
    }

    public function testRendersServiceUnavailableWhenUpstreamUnreachable(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willThrowException(new \RuntimeException('connection refused'));
        Services::injectMock('curlrequest', $http);
        Services::resetSingle('hubClient');

        $result = $this->get('/api/v1/users/1');

        $result->assertStatus(503);
        $body = $this->decodeBody($result);
        $this->assertSame('error', $body['status']);
    }

    /**
     * Read the raw response body. `TestResponse::getBody()` wraps the body in
     * HTML for human display; the real body lives on `response()->getBody()`.
     *
     * @return array<string, mixed>
     */
    private function decodeBody(\CodeIgniter\Test\TestResponse $result): array
    {
        $body    = (string) $result->response()->getBody();
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Mock the shared `curlrequest` service so the HubClient calls a stub.
     *
     * @param array<string, mixed>|null $capturedOptions Captured options of the call.
     */
    private function mockCurl(int $status, string $body, ?array &$capturedOptions = null): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$capturedOptions, $response): ResponseInterface {
                $capturedOptions = $options;

                return $response;
            },
        );

        Services::injectMock('curlrequest', $http);
        Services::resetSingle('hubClient');
    }
}
