<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminIamRoleWorkspaceSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;
use Tests\Support\ApiTestCase;

/**
 * Regression for extracting the raw `hubDashboardClient()->get()` call that
 * used to live inline in `AdminIamWorkspaceController` into
 * `AdminIamRoleWorkspaceSource`, matching the rest of the `Me/Admin*` family.
 */
final class AdminIamWorkspaceTest extends ApiTestCase
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
        $result = $this->get('/api/v1/me/admin-iam/roles/1/workspace');

        $result->assertStatus(401);
    }

    public function testReturnsRoleWorkspaceEnvelope(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminIamRoleWorkspaceSourceInterface::class);
        $source->expects($this->once())
            ->method('workspace')
            ->with(1, 'valid-token')
            ->willReturn([
                'role' => ['id' => 1, 'name' => 'Editor'],
                'allPermissions' => [['id' => 1, 'code' => 'cms.entries.read']],
                'assignedPermissionIds' => [1],
            ]);
        Services::injectMock('adminReadIamRoleWorkspace', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-iam/roles/1/workspace');

        $result->assertStatus(200);
        $body = $this->decodeBody($result);
        $this->assertSame('success', $body['status']);
        $this->assertSame(['id' => 1, 'name' => 'Editor'], $body['data']['role']);
        $this->assertSame([1], $body['data']['assignedPermissionIds']);
    }

    public function testReturns503WhenSourceFails(): void
    {
        $this->mockEffectiveUser();
        $source = $this->createMock(AdminIamRoleWorkspaceSourceInterface::class);
        $source->method('workspace')->willThrowException(new RuntimeException('hub unavailable'));
        Services::injectMock('adminReadIamRoleWorkspace', $source);

        $result = $this
            ->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-iam/roles/1/workspace');

        $result->assertStatus(503);
    }

    /** @param list<string>|null $permissions */
    private function mockEffectiveUser(?array $permissions = null): void
    {
        $response = $this->jsonResponse(200, [
            'data' => [
                'id' => 42,
                'permissions' => $permissions ?? ['iam.admin-access'],
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
