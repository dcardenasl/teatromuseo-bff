<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Domain;

use App\Libraries\Domain\DomainClient;
use CodeIgniter\HTTP\CURLRequest;
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
