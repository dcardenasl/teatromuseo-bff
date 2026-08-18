<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminEventWorkspaceSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

final class AdminEventWorkspaceTest extends ApiTestCase
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

    public function testReturnsEventWorkspaceEnvelope(): void
    {
        $permissions = ['event.events.read', 'event.event-types.read', 'cms.languages.read'];
        $this->mockEffectiveUser($permissions);
        $source = $this->createMock(AdminEventWorkspaceSourceInterface::class);
        $source->expects($this->once())
            ->method('workspace')
            ->with(9, $permissions)
            ->willReturn(['event' => ['id' => 9], 'eventTypes' => [], 'languages' => []]);
        Services::injectMock('adminReadEventWorkspace', $source);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-event/events/9/workspace');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('event-workspace', $body['data']['context']);
        $this->assertSame(['id' => 9], $body['data']['sections']['event']);
    }

    /** @param list<string> $permissions */
    private function mockEffectiveUser(array $permissions): void
    {
        $response = $this->jsonResponse(200, ['data' => ['id' => 42, 'permissions' => $permissions]]);
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($response);
        Services::injectMock('curlrequest', $http);
        Services::resetSingle('hubDashboardClient');
    }

    /** @return array<string,mixed> */
    private function decodeBody(\CodeIgniter\Test\TestResponse $result): array
    {
        $decoded = json_decode((string) $result->response()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }
}
