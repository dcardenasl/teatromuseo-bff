<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use Config\Services;
use dcardenasl\Ci4ApiCore\Http\Client\HubClient;

/**
 * Pins the template-driven public passthrough contract: routes generated from
 * `template.json.public_endpoints[]` call `PublicProxyController::forward()`
 * and preserve the upstream response unchanged.
 */
final class PublicProxyControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpControllerTestTrait();
        Services::resetSingle('hubClient');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('hubClient');
        parent::tearDown();
    }

    public function testForwardPassesTheRequestedPathToTheHub(): void
    {
        $upstream = $this->createMock(ResponseInterface::class);
        $upstream->method('getStatusCode')->willReturn(200);
        $upstream->method('getBody')->willReturn(json_encode(['data' => ['ok' => true]], JSON_THROW_ON_ERROR));
        $upstream->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json'],
            ['Content-Language', ''],
        ]);

        $hub = $this->createMock(HubClient::class);
        $hub->expects($this->once())
            ->method('forward')
            ->with($this->isInstanceOf(IncomingRequest::class), '/api/v1/plans/active')
            ->willReturn($upstream);
        Services::injectMock('hubClient', $hub);

        $result = $this
            ->controller(\App\Controllers\Api\V1\PublicProxyController::class)
            ->execute('forward', 'plans/active');

        $result->assertStatus(200);
        $body = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame(['ok' => true], $body['data']);
    }
}
