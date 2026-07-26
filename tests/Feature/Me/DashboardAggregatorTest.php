<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

/**
 * BFF-106: `GET /api/v1/me/dashboard` demonstrates the introspect-auth +
 * aggregate pattern. The token is validated against the hub (cached), the
 * user id + permissions are populated on the request, and the controller
 * aggregates a hub call + token-derived data into one envelope.
 */
class DashboardAggregatorTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('hub.url=http://hub.test');
        putenv('hub.apiKey=test-key');
        Services::resetSingle('hubClient');
    }

    protected function tearDown(): void
    {
        putenv('hub.url');
        putenv('hub.apiKey');
        Services::resetSingle('hubClient');
        parent::tearDown();
    }

    public function testReturns401WhenAuthorizationHeaderIsMissing(): void
    {
        $result = $this->get('/api/v1/me/dashboard');

        $result->assertStatus(401);
    }

    public function testReturns401WhenIntrospectRejectsTheToken(): void
    {
        $this->mockHubCalls([
            $this->jsonResponse(200, ['data' => ['valid' => false, 'error' => 'expired']]),
        ]);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer expired-token'])
            ->get('/api/v1/me/dashboard');

        $result->assertStatus(401);
    }

    public function testReturns200AndAggregatesProfileWithTokenPermissions(): void
    {
        $this->mockHubCalls([
            // 1) /auth/introspect — token is valid for user 42 with scope.
            $this->jsonResponse(200, ['data' => [
                'valid'       => true,
                'uid'         => 42,
                'permissions' => ['users.read', 'dashboard.view'],
                'exp'         => time() + 3600,
            ]]),
            // 2) /api/v1/users/42 — hub returns the profile.
            $this->jsonResponse(200, ['data' => [
                'id'    => 42,
                'name'  => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ]]),
        ]);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/dashboard');

        $result->assertStatus(200);
        $body = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('success', $body['status']);
        $this->assertSame(42, $body['data']['profile']['id']);
        $this->assertSame('Ada Lovelace', $body['data']['profile']['name']);
        $this->assertSame(['users.read', 'dashboard.view'], $body['data']['permissions']['scope']);
    }

    public function testReturns503WhenHubProfileFetchFails(): void
    {
        $this->mockHubCalls([
            // Introspect succeeds.
            $this->jsonResponse(200, ['data' => [
                'valid'       => true,
                'uid'         => 42,
                'permissions' => ['dashboard.view'],
            ]]),
            // First profile call: 503 — AbstractServiceClient retries on 5xx.
            $this->jsonResponse(503, ['message' => 'hub overloaded']),
            // Retry: still 503.
            $this->jsonResponse(503, ['message' => 'hub overloaded']),
        ]);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/dashboard');

        $result->assertStatus(503);
        $body = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('error', $body['status']);
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function mockHubCalls(array $responses): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturnOnConsecutiveCalls(...$responses);

        Services::injectMock('curlrequest', $http);
        Services::resetSingle('hubClient');
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
