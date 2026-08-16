<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Domain;

use App\Libraries\Domain\DomainClient;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use InvalidArgumentException;

class DomainClientTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset services instances to prevent tests leakage
        Services::reset();
    }

    public function testDomainClientInstantiation(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $client = new DomainClient($http, 'http://catalog-domain.test', 10);

        $this->assertInstanceOf(DomainClient::class, $client);
    }

    public function testGetReadsJsonWithBearerToken(): void
    {
        $capturedMethod  = null;
        $capturedUrl     = null;
        $capturedOptions = null;
        $response        = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode([
            'data' => ['sections' => ['pages' => ['total' => 3]]],
        ], JSON_THROW_ON_ERROR));

        $http = $this->createMock(CURLRequest::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions, $response): ResponseInterface {
                $capturedMethod  = $method;
                $capturedUrl     = $url;
                $capturedOptions = $options;

                return $response;
            });

        $client = new DomainClient($http, 'http://catalog-domain.test');

        $result = $client->get('/catalog/dashboard/summary', 'visitor-token');

        $this->assertSame(['sections' => ['pages' => ['total' => 3]]], $result);
        $this->assertSame('GET', $capturedMethod);
        $this->assertSame('http://catalog-domain.test/catalog/dashboard/summary', $capturedUrl);
        $this->assertSame('Bearer visitor-token', $capturedOptions['headers']['Authorization']);
        $this->assertSame('application/json', $capturedOptions['headers']['Accept']);
    }

    public function testServicesDomainClientThrowsOnUnconfiguredDomain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Upstream domain URL for 'unconfigured' is not defined in Config\Bff::\$domains.");

        Services::domainClient('unconfigured');
    }

    public function testServicesDomainClientReturnsConfiguredClient(): void
    {
        $bffConfig = config('Bff');
        $bffConfig->domains = [
            'billing' => 'http://billing-domain.test',
        ];

        $client = Services::domainClient('billing', false);

        $this->assertInstanceOf(DomainClient::class, $client);
    }

    public function testServicesDomainClientReturnsSharedInstanceByDefault(): void
    {
        $bffConfig = config('Bff');
        $bffConfig->domains = [
            'billing' => 'http://billing-domain.test',
        ];

        $client1 = Services::domainClient('billing');
        $client2 = Services::domainClient('billing');

        $this->assertSame($client1, $client2);
    }

    public function testForwardedHeadersIncludeWebhookSignatureHeaders(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $client = new DomainClient($http, 'http://billing-domain.test');

        $appConfig = new \Config\App();
        $request = new \CodeIgniter\HTTP\IncomingRequest(
            $appConfig,
            new \CodeIgniter\HTTP\SiteURI($appConfig, 'webhook'),
            null,
            new \CodeIgniter\HTTP\UserAgent()
        );
        $request->setHeader('X-Webhook-Token', 'secret');
        $request->setHeader('X-Twilio-Email-Event-Webhook-Signature', 'sig==');
        $request->setHeader('X-Twilio-Email-Event-Webhook-Timestamp', '1234567890');
        $request->setHeader('X-Not-Allowed', 'should-be-dropped');

        $method = new \ReflectionMethod($client, 'buildForwardedHeaders');
        $headers = $method->invoke($client, $request);

        $this->assertSame('secret', $headers['X-Webhook-Token']);
        $this->assertSame('sig==', $headers['X-Twilio-Email-Event-Webhook-Signature']);
        $this->assertSame('1234567890', $headers['X-Twilio-Email-Event-Webhook-Timestamp']);
        $this->assertArrayNotHasKey('X-Not-Allowed', $headers);
    }
}
