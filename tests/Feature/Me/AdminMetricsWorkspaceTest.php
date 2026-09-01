<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminMetricsWorkspaceSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Tests\Support\ApiTestCase;

/**
 * Regression for extracting the raw `hubDashboardClient()->get()` call that
 * used to live inline in `AdminMetricsWorkspaceController` into
 * `AdminMetricsWorkspaceSource`, matching the rest of the `Me/Admin*` family.
 */
final class AdminMetricsWorkspaceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('hub.url=http://hub.test');
        putenv('hub.apiKey=test-key');
        Services::reset();
    }

    protected function tearDown(): void
    {
        putenv('hub.url');
        putenv('hub.apiKey');
        Services::reset();
        parent::tearDown();
    }

    public function testReturns401WhenAuthorizationHeaderIsMissing(): void
    {
        $result = $this->get('/api/v1/me/admin-metrics/workspace');

        $result->assertStatus(401);
    }

    public function testDefaultsToTwentyFourHours(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminMetricsWorkspaceSourceInterface::class);
        $source->expects($this->once())
            ->method('workspace')
            ->with('24h', 'valid-token')
            ->willReturn(['summary' => ['total' => 5], 'timeseries' => []]);
        Services::injectMock('adminReadMetricsWorkspace', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-metrics/workspace');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('24h', $body['data']['period']);
        $this->assertSame(['total' => 5], $body['data']['summary']);
    }

    public function testReturnsMetricsWorkspaceEnvelopeForRequestedPeriod(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminMetricsWorkspaceSourceInterface::class);
        $source->expects($this->once())
            ->method('workspace')
            ->with('7d', 'valid-token')
            ->willReturn([
                'summary' => ['total' => 42],
                'timeseries' => [['label' => '2026-08-11', 'value' => 10]],
            ]);
        Services::injectMock('adminReadMetricsWorkspace', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-metrics/workspace?period=7d');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('success', $body['status']);
        $this->assertSame('7d', $body['data']['period']);
        $this->assertSame(['total' => 42], $body['data']['summary']);
        $this->assertSame([['label' => '2026-08-11', 'value' => 10]], $body['data']['timeseries']);
    }

    public function testReturns503WhenSourceFails(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminMetricsWorkspaceSourceInterface::class);
        $source->method('workspace')->willThrowException(new RuntimeException('hub unavailable'));
        Services::injectMock('adminReadMetricsWorkspace', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-metrics/workspace');

        $result->assertStatus(503);
    }

    /** @param list<string>|null $permissions */
    private function mockEffectiveUser(?array $permissions = null): void
    {
        $response = $this->jsonResponse(200, [
            'data' => [
                'id' => 42,
                'permissions' => $permissions ?? ['metrics.read'],
            ],
        ]);
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($response);

        Services::injectMock('curlrequest', $http);
        Services::resetSingle('hubDashboardClient');
    }

    /** @return array<string, mixed> */
    private function decodeBody(\CodeIgniter\Test\TestResponse $result): array
    {
        $decoded = json_decode((string) $result->response()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }
}
