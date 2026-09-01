<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\AdminRead\Contracts\AdminCmsWizardSourceInterface;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Tests\Support\ApiTestCase;

final class AdminCmsWizardTest extends ApiTestCase
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

    public function testReturnsWizardBootstrapEnvelope(): void
    {
        $permissions = ['cms.entries.read'];
        $this->mockEffectiveUser($permissions);
        $source = $this->createMock(AdminCmsWizardSourceInterface::class);
        $source->expects($this->once())
            ->method('bootstrap')
            ->with($permissions, 'valid-token')
            ->willReturn(['config' => ['languages' => []], 'blockTypes' => []]);
        Services::injectMock('adminReadCmsWizard', $source);

        $result = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->get('/api/v1/me/admin-cms/wizard-bootstrap');

        $result->assertStatus(200);
        $body = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('wizard-bootstrap', $body['data']['context']);
        $this->assertSame([], $body['data']['sections']['blockTypes']);
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

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }
}
