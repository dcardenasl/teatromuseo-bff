<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

final class AdminDashboardTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('hub.url=http://hub.test');
        putenv('hub.apiKey=test-key');
        putenv('BFF_DOMAINS=cms:http://cms.test,catalog:http://catalog.test,event:http://event.test');
        Services::reset();
    }

    protected function tearDown(): void
    {
        putenv('hub.url');
        putenv('hub.apiKey');
        putenv('BFF_DOMAINS');
        Services::reset();
        parent::tearDown();
    }

    public function testReturns401WhenAuthorizationHeaderIsMissing(): void
    {
        $result = $this->get('/api/v1/me/admin-dashboard');

        $result->assertStatus(401);
    }

    public function testReturns401WhenIntrospectRejectsTheToken(): void
    {
        $this->mockUpstreamCalls([
            $this->jsonResponse(200, ['data' => ['valid' => false, 'error' => 'expired']]),
        ]);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer expired-token'])
            ->get('/api/v1/me/admin-dashboard');

        $result->assertStatus(401);
    }

    public function testReturns200WithAllFourSourcesAvailable(): void
    {
        $this->mockUpstreamCalls([
            $this->validIntrospection(),
            $this->summaryResponse(['users' => ['total' => 4]]),
            $this->summaryResponse(['pages' => ['total' => 7]]),
            $this->summaryResponse(['works' => ['total' => 12]]),
            $this->summaryResponse(['events' => ['total' => 3]]),
        ]);

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
        $this->assertSame(['total' => 7], $body['data']['sections']['cms']['pages']);
    }

    public function testReturns200WhenOneSourceDegrades(): void
    {
        $this->mockUpstreamCalls([
            $this->validIntrospection(),
            $this->summaryResponse(['users' => ['total' => 4]]),
            $this->jsonResponse(503, ['message' => 'cms unavailable']),
            $this->jsonResponse(503, ['message' => 'cms unavailable']),
            $this->summaryResponse(['works' => ['total' => 12]]),
            $this->summaryResponse(['events' => ['total' => 3]]),
        ]);

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
        $this->assertSame(['total' => 12], $body['data']['sections']['catalog']['works']);
    }

    public function testReturns200WhenAllSourcesAreUnavailable(): void
    {
        $responses = [$this->validIntrospection()];
        for ($i = 0; $i < 8; $i++) {
            $responses[] = $this->jsonResponse(503, ['message' => 'upstream unavailable']);
        }
        $this->mockUpstreamCalls($responses);

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

    private function validIntrospection(): ResponseInterface
    {
        return $this->jsonResponse(200, ['data' => [
            'valid'       => true,
            'uid'         => 42,
            'permissions' => ['dashboard.view'],
            'exp'         => time() + 3600,
        ]]);
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
