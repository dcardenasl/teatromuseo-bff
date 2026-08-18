<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminCatalogCollectionItemSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

final class AdminCatalogWorkspaceTest extends ApiTestCase
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

    public function testReturnsCollectionItemWorkspaceEnvelope(): void
    {
        $permissions = ['catalog.collectionItem.read', 'catalog.category.read', 'catalog.technique.read', 'cms.languages.read'];
        $this->mockEffectiveUser($permissions);
        $source = $this->createMock(AdminCatalogCollectionItemSourceInterface::class);
        $source->expects($this->once())
            ->method('workspace')
            ->with(7, $permissions)
            ->willReturn(['collectionItem' => ['id' => 7], 'categories' => [], 'techniques' => [], 'languages' => []]);
        Services::injectMock('adminReadCatalogCollectionItemWorkspace', $source);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-catalog/collection-items/7/workspace');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('catalog-collection-item-workspace', $body['data']['context']);
        $this->assertSame(['id' => 7], $body['data']['sections']['collectionItem']);
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
