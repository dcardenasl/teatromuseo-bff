<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

final class AdminHubWorkspaceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::reset();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testMetricsWorkspaceUsesOneHubContract(): void
    {
        $this->mockUpstream([
            $this->jsonResponse(200, ['data' => ['id' => 42, 'permissions' => ['metrics.read']]]),
            $this->jsonResponse(200, ['data' => [
                'summary' => ['request_stats' => ['total_requests' => 3]],
                'timeseries' => ['dates' => ['2026-08-18'], 'requests' => [3]],
            ]]),
        ]);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-metrics/workspace?period=7d');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('success', $body['status']);
        $this->assertSame(['request_stats' => ['total_requests' => 3]], $body['data']['summary']);
        $this->assertSame('7d', $body['data']['period']);
    }

    public function testIamRoleWorkspacePreservesHubPayload(): void
    {
        $this->mockUpstream([
            $this->jsonResponse(200, ['data' => ['id' => 42, 'permissions' => ['iam.superadmin-access']]]),
            $this->jsonResponse(200, ['data' => [
                'role' => ['id' => 7, 'code' => 'editor'],
                'allPermissions' => [['id' => 1, 'code' => 'cms.pages.read']],
                'assignedPermissionIds' => [1],
            ]]),
        ]);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-iam/roles/7/workspace');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame(7, $body['data']['role']['id']);
        $this->assertSame([1], $body['data']['assignedPermissionIds']);
    }

    /** @param list<ResponseInterface> $responses */
    private function mockUpstream(array $responses): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturnOnConsecutiveCalls(...$responses);
        Services::injectMock('curlrequest', $http);
        Services::resetSingle('hubDashboardClient');
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }

    /** @return array<string, mixed> */
    private function decodeBody(\CodeIgniter\Test\TestResponse $result): array
    {
        $decoded = json_decode((string) $result->response()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
