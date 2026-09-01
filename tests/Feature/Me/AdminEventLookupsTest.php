<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminEventLookupSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Tests\Support\ApiTestCase;

final class AdminEventLookupsTest extends ApiTestCase
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

    public function testReturnsEmptySuccessfulCatalogDistinctFromUnavailable(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminEventLookupSourceInterface::class);
        $source->expects($this->once())
            ->method('read')
            ->with('occurrence', $this->callback(static fn (mixed $permissions): bool => is_array($permissions)))
            ->willReturn(['events' => [], 'venues' => []]);
        Services::injectMock('adminReadEventLookups', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-event-lookups/occurrence');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('success', $body['status']);
        $this->assertSame('occurrence', $body['data']['context']);
        $this->assertSame(['event' => 'ok', 'state' => 'ok'], $body['data']['source']);
        $this->assertSame([], $body['data']['sections']['events']);
        $this->assertSame([], $body['data']['sections']['venues']);
    }

    public function testRejectsUnknownContextBeforeReadingSource(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminEventLookupSourceInterface::class);
        $source->expects($this->never())->method('read');
        Services::injectMock('adminReadEventLookups', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-event-lookups/not-a-context');

        $result->assertStatus(422);
    }

    public function testReturns403WhenEventPermissionIsMissing(): void
    {
        $this->mockEffectiveUser(['event.events.read']);
        $source = $this->createMock(AdminEventLookupSourceInterface::class);
        $source->expects($this->once())
            ->method('read')
            ->with('occurrence', ['event.events.read'])
            ->willThrowException(new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException('forbidden'));
        Services::injectMock('adminReadEventLookups', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-event-lookups/occurrence');

        $result->assertStatus(403);
    }

    public function testReturns503WhenEventSourceFails(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminEventLookupSourceInterface::class);
        $source->method('read')->willThrowException(new RuntimeException('event database unavailable'));
        Services::injectMock('adminReadEventLookups', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-event-lookups/occurrence');

        $result->assertStatus(503);
        $this->assertSame('error', $this->decodeBody($result)['status']);
    }

    /** @param list<string>|null $permissions */
    private function mockEffectiveUser(?array $permissions = null): void
    {
        $response = $this->jsonResponse(200, [
            'data' => [
                'id'          => 42,
                'permissions' => $permissions ?? ['event.events.read', 'event.venues.read'],
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
