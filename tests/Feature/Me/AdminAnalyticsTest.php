<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminAnalyticsSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Tests\Support\ApiTestCase;

final class AdminAnalyticsTest extends ApiTestCase
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
        $result = $this->get('/api/v1/me/admin-analytics');

        $result->assertStatus(401);
    }

    public function testReturnsCompleteProjectionForRequestedPeriod(): void
    {
        $this->mockEffectiveUser();
        $reader = $this->createMock(AdminAnalyticsSourceInterface::class);
        $reader->expects($this->once())
            ->method('read')
            ->with($this->permissionScope(), '24h')
            ->willReturn($this->analyticsSections('24h'));
        Services::injectMock('adminReadCmsAnalytics', $reader);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-analytics?period=24h');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);

        $this->assertSame('success', $body['status']);
        $this->assertSame(['cms' => 'ok', 'state' => 'ok'], $body['data']['source']);
        $this->assertSame('24h', $body['data']['sections']['overview']['period']);
        $this->assertSame(10, $body['data']['sections']['pages']['data'][0]['views']);
        $this->assertSame(['desktop' => 8, 'mobile' => 2], [
            'desktop' => $body['data']['sections']['devices']['desktop'],
            'mobile' => $body['data']['sections']['devices']['mobile'],
        ]);
    }

    public function testDefaultsToSevenDays(): void
    {
        $this->mockEffectiveUser();
        $reader = $this->createMock(AdminAnalyticsSourceInterface::class);
        $reader->expects($this->once())
            ->method('read')
            ->with($this->permissionScope(), '7d')
            ->willReturn($this->analyticsSections('7d'));
        Services::injectMock('adminReadCmsAnalytics', $reader);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-analytics');

        $result->assertStatus(200);
    }

    public function testRejectsUnsupportedPeriodBeforeReadingSource(): void
    {
        $this->mockEffectiveUser();
        $reader = $this->createMock(AdminAnalyticsSourceInterface::class);
        $reader->expects($this->never())->method('read');
        Services::injectMock('adminReadCmsAnalytics', $reader);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-analytics?period=90d');

        $result->assertStatus(422);
    }

    public function testReturns403WhenCmsAnalyticsPermissionIsMissing(): void
    {
        $this->mockEffectiveUser(['dashboard.view']);
        $reader = $this->createMock(AdminAnalyticsSourceInterface::class);
        $reader->expects($this->once())
            ->method('read')
            ->with(['dashboard.view'], '7d')
            ->willThrowException(new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException('forbidden'));
        Services::injectMock('adminReadCmsAnalytics', $reader);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-analytics');

        $result->assertStatus(403);
    }

    public function testReturns503WhenCmsAnalyticsSourceFails(): void
    {
        $this->mockEffectiveUser();
        $reader = $this->createMock(AdminAnalyticsSourceInterface::class);
        $reader->method('read')->willThrowException(new RuntimeException('database unavailable'));
        Services::injectMock('adminReadCmsAnalytics', $reader);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-analytics');

        $result->assertStatus(503);
        $this->assertSame('error', $this->decodeBody($result)['status']);
    }

    /** @param list<string>|null $permissions */
    private function mockEffectiveUser(?array $permissions = null): void
    {
        $response = $this->jsonResponse(200, [
            'data' => [
                'id'          => 42,
                'permissions' => $permissions ?? ['cms.analytics.read', 'dashboard.view'],
            ],
        ]);
        $http = $this->createMock(CURLRequest::class);
        $http->method('request')->willReturn($response);

        Services::injectMock('curlrequest', $http);
        Services::resetSingle('hubDashboardClient');
    }

    private function permissionScope(): \PHPUnit\Framework\Constraint\Constraint
    {
        return $this->callback(static function (mixed $permissions): bool {
            return is_array($permissions)
                && in_array('cms.analytics.read', $permissions, true);
        });
    }

    /** @return array<string, mixed> */
    private function analyticsSections(string $period): array
    {
        return [
            'overview' => [
                'total_views' => 12,
                'unique_visitors' => 5,
                'top_page' => '/inicio',
                'top_page_title' => 'Inicio',
                'top_referrer' => 'google.com',
                'period' => $period,
            ],
            'pages' => ['data' => [['url' => '/inicio', 'page_title' => 'Inicio', 'views' => 10, 'percentage' => 83.3]], 'period' => $period],
            'referrers' => ['data' => [['domain' => 'google.com', 'views' => 8, 'percentage' => 66.7]], 'period' => $period],
            'devices' => ['desktop' => 8, 'mobile' => 2, 'tablet' => 0, 'bot' => 0, 'unknown' => 0, 'period' => $period],
            'timeseries' => ['data' => [['label' => '2026-08-17', 'views' => 12, 'unique_visitors' => 5]], 'period' => $period],
        ];
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
