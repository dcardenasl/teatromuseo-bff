<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminCmsBootstrapSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

final class AdminCmsBootstrapTest extends ApiTestCase
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

    public function testReturnsEntryBootstrapEnvelope(): void
    {
        $permissions = [
            'cms.entries.read',
            'cms.languages.read',
            'cms.collections.read',
        ];
        $this->mockEffectiveUser($permissions);
        $source = $this->createMock(AdminCmsBootstrapSourceInterface::class);
        $source->expects($this->once())
            ->method('entryFormOptions')
            ->with(null, $permissions)
            ->willReturn(['languages' => [['id' => 1]], 'collections' => []]);
        Services::injectMock('adminReadCmsBootstrap', $source);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-cms/entry-form-options');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('success', $body['status']);
        $this->assertSame('entry-form-options', $body['data']['context']);
        $this->assertSame(['cms' => 'ok', 'state' => 'ok'], $body['data']['source']);
        $this->assertSame([['id' => 1]], $body['data']['sections']['languages']);
    }

    public function testPassesOptionalPageAndMenuIdentifiersToTheSource(): void
    {
        $permissions = [
            'cms.pages.read',
            'cms.languages.read',
            'cms.collections.read',
        ];
        $this->mockEffectiveUser($permissions);
        $source = $this->createMock(AdminCmsBootstrapSourceInterface::class);
        $source->expects($this->once())
            ->method('pageFormOptions')
            ->with(35, $permissions)
            ->willReturn(['pages' => []]);
        Services::injectMock('adminReadCmsBootstrap', $source);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-cms/page-form-options/35');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('page-form-options', $body['data']['context']);
    }

    public function testReturnsForbiddenWhenSourceRejectsPermissionScope(): void
    {
        $permissions = ['cms.entries.read'];
        $this->mockEffectiveUser($permissions);
        $source = $this->createMock(AdminCmsBootstrapSourceInterface::class);
        $source->expects($this->once())
            ->method('entryFormOptions')
            ->willThrowException(new \dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException('forbidden'));
        Services::injectMock('adminReadCmsBootstrap', $source);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-cms/entry-form-options');

        $result->assertStatus(403);
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
