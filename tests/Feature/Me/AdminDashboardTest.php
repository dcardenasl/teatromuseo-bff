<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminDashboardSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Tests\Support\ApiTestCase;

final class AdminDashboardTest extends ApiTestCase
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
        $result = $this->get('/api/v1/me/admin-dashboard');

        $result->assertStatus(401);
    }

    public function testReturns401WhenHubRejectsTheAuthenticatedUser(): void
    {
        $this->mockUpstreamCalls([
            $this->jsonResponse(401, ['message' => 'invalid token']),
        ]);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer expired-token'])
            ->get('/api/v1/me/admin-dashboard');

        $result->assertStatus(401);
    }

    public function testReturns200WithAllFourSourcesAvailable(): void
    {
        $this->mockUpstreamCalls([
            $this->validAuthenticatedUser(),
            $this->summaryResponse(['users' => ['total' => 4]]),
        ]);
        $this->mockDashboardSources();

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-dashboard');

        $result->assertStatus(200);
        $body = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('success', $body['status']);
        $this->assertSame('ok', $body['data']['source']['hub']);
        $this->assertSame('ok', $body['data']['source']['cms']);
        $this->assertSame('ok', $body['data']['source']['catalog']);
        $this->assertSame('ok', $body['data']['source']['event']);
        $this->assertSame('ok', $body['data']['source']['state']);
        $this->assertSame(['pages' => 7], $body['data']['sections']['cms']['counts']);
    }

    public function testReturns200WhenOneSourceDegrades(): void
    {
        $this->mockUpstreamCalls([
            $this->validAuthenticatedUser(),
            $this->summaryResponse(['users' => ['total' => 4]]),
        ]);
        $this->mockDashboardSources(cmsThrows: true);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-dashboard');

        $result->assertStatus(200);
        $body = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('ok', $body['data']['source']['hub']);
        $this->assertSame('unavailable', $body['data']['source']['cms']);
        $this->assertSame('ok', $body['data']['source']['catalog']);
        $this->assertSame('ok', $body['data']['source']['event']);
        $this->assertSame('partial', $body['data']['source']['state']);
        $this->assertSame([], $body['data']['sections']['cms']);
        $this->assertSame(['collection_items' => 12], $body['data']['sections']['catalog']['counts']);
    }

    public function testReturns200WhenAllSourcesAreUnavailable(): void
    {
        $this->mockUpstreamCalls([
            $this->validAuthenticatedUser(),
            $this->jsonResponse(503, ['message' => 'upstream unavailable']),
            $this->jsonResponse(503, ['message' => 'upstream unavailable']),
        ]);
        $this->mockDashboardSources(allThrow: true);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-dashboard');

        $result->assertStatus(200);
        $body = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('unavailable', $body['data']['source']['hub']);
        $this->assertSame('unavailable', $body['data']['source']['cms']);
        $this->assertSame('unavailable', $body['data']['source']['catalog']);
        $this->assertSame('unavailable', $body['data']['source']['event']);
        $this->assertSame('unavailable', $body['data']['source']['state']);
        $this->assertSame([
            'hub' => [],
            'cms' => [],
            'catalog' => [],
            'event' => [],
        ], $body['data']['sections']);
    }

    /** @param list<ResponseInterface> $responses */
    private function mockUpstreamCalls(array $responses): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturnOnConsecutiveCalls(...$responses);

        Services::injectMock('curlrequest', $http);
    }

    private function validAuthenticatedUser(): ResponseInterface
    {
        return $this->jsonResponse(200, ['data' => [
            'id'          => 42,
            'permissions' => [
                'dashboard.view',
                'cms.pages.read',
                'catalog.collectionItem.read',
                'event.events.read',
            ],
            'exp'         => time() + 3600,
        ]]);
    }

    private function mockDashboardSources(bool $cmsThrows = false, bool $allThrow = false): void
    {
        foreach ([
            'adminReadCmsDashboard' => ['counts' => ['pages' => 7]],
            'adminReadCatalogDashboard' => ['counts' => ['collection_items' => 12]],
            'adminReadEventDashboard' => ['counts' => ['events' => 3]],
        ] as $service => $sections) {
            $reader = $this->createMock(AdminDashboardSourceInterface::class);
            $shouldThrow = $allThrow || ($cmsThrows && $service === 'adminReadCmsDashboard');
            if ($shouldThrow) {
                $reader->method('read')
                    ->with($this->effectivePermissionScope())
                    ->willThrowException(new RuntimeException('source unavailable'));
            } else {
                $reader->method('read')
                    ->with($this->effectivePermissionScope())
                    ->willReturn(['sections' => $sections]);
            }
            Services::injectMock($service, $reader);
        }
    }

    private function effectivePermissionScope(): \PHPUnit\Framework\Constraint\Constraint
    {
        return $this->callback(static function (mixed $permissions): bool {
            return is_array($permissions)
                && in_array('cms.pages.read', $permissions, true)
                && in_array('catalog.collectionItem.read', $permissions, true)
                && in_array('event.events.read', $permissions, true);
        });
    }

    /** @param array<string, mixed> $sections */
    private function summaryResponse(array $sections): ResponseInterface
    {
        return $this->jsonResponse(200, ['data' => [
            'version'     => 1,
            'generated_at' => date(DATE_ATOM),
            'sections'    => $sections,
        ]]);
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
