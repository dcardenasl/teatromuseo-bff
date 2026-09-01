<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminFileUsageSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Tests\Support\ApiTestCase;

final class AdminFileUsagesTest extends ApiTestCase
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

    public function testReturnsCompleteDeduplicatedUsages(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminFileUsageSourceInterface::class);
        $source->expects($this->once())
            ->method('readSnapshot')
            ->willReturn([
                'complete' => true,
                'source' => ['hub' => 'ok', 'cms' => 'ok', 'state' => 'ok'],
                'usages' => [
                    ['resource' => 'pages', 'resource_id' => 2],
                    ['resource' => 'block_instances', 'resource_id' => 3],
                ],
            ]);
        Services::injectMock('adminReadFileUsages', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-files/7/usages');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertTrue($body['data']['complete']);
        $this->assertSame(['hub' => 'ok', 'cms' => 'ok', 'state' => 'ok'], $body['data']['source']);
        $this->assertCount(2, $body['data']['sections']['usages']);
    }

    public function testMarksResultUnavailableWhenHubSnapshotFails(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminFileUsageSourceInterface::class);
        $source->method('readSnapshot')->willThrowException(new RuntimeException('hub unavailable'));
        Services::injectMock('adminReadFileUsages', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-files/7/usages');

        $body = $this->decodeBody($result);
        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertFalse($body['data']['complete']);
        $this->assertSame('unavailable', $body['data']['source']['hub']);
        $this->assertSame('unavailable', $body['data']['source']['state']);
    }

    public function testEmptySuccessfulSourcesAreCompleteAndEmpty(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminFileUsageSourceInterface::class);
        $source->method('readSnapshot')->willReturn([
            'complete' => true,
            'source' => ['hub' => 'ok', 'cms' => 'ok', 'state' => 'ok'],
            'usages' => [],
        ]);
        Services::injectMock('adminReadFileUsages', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-files/7/usages');

        $body = $this->decodeBody($result);
        $this->assertTrue($body['data']['complete']);
        $this->assertSame([], $body['data']['sections']['usages']);
    }

    /** @param list<string>|null $permissions */
    private function mockEffectiveUser(?array $permissions = null): void
    {
        $response = $this->jsonResponse(200, ['data' => [
            'id' => 42,
            'permissions' => $permissions ?? ['files.read', 'cms.entries.read'],
        ]]);
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
