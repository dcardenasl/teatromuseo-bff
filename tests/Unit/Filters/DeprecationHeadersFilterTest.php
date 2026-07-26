<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Api as ApiConfig;
use Config\App as AppConfig;
use dcardenasl\Ci4ApiCore\Http\Filters\DeprecationHeadersFilter;

/**
 * DeprecationHeadersFilter Unit Tests
 *
 * Audit B7.2 (2026-05-06): pin behavior of the filter that emits
 * Deprecation / Sunset / Link headers based on `Config\Api::$apiVersions`.
 *
 * @internal
 */
final class DeprecationHeadersFilterTest extends CIUnitTestCase
{
    private DeprecationHeadersFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new DeprecationHeadersFilter();
    }

    protected function tearDown(): void
    {
        Factories::reset('config');
        parent::tearDown();
    }

    /**
     * Override the shared `Config\Api` instance with a fixture so the filter
     * (which retrieves it via `config('Api')`) sees the version map we want
     * to test against.
     *
     * @param array<string, array{status: string, deprecated_at: ?string, sunset_at: ?string, successor: ?string}> $versions
     */
    private function withApiVersions(array $versions): void
    {
        $config = new ApiConfig();
        $config->apiVersions = $versions;
        Factories::injectMock('config', 'Api', $config);
    }

    private function createRequest(string $path): IncomingRequest
    {
        $uri = $this->createMock(URI::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(IncomingRequest::class);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }

    private function createResponse(): Response
    {
        return new Response(new AppConfig());
    }

    public function testCurrentVersionEmitsNoDeprecationHeaders(): void
    {
        $this->withApiVersions([
            'v1' => ['status' => 'current', 'deprecated_at' => null, 'sunset_at' => null, 'successor' => null],
        ]);

        $response = $this->filter->after($this->createRequest('/api/v1/users'), $this->createResponse());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertFalse($response->hasHeader('Deprecation'));
        $this->assertFalse($response->hasHeader('Sunset'));
        $this->assertFalse($response->hasHeader('Link'));
    }

    public function testDeprecatedVersionEmitsDeprecationAndSunsetHeaders(): void
    {
        $this->withApiVersions([
            'v1' => [
                'status'        => 'deprecated',
                'deprecated_at' => '2026-09-01',
                'sunset_at'     => '2027-03-01',
                'successor'     => 'v2',
            ],
            'v2' => ['status' => 'current', 'deprecated_at' => null, 'sunset_at' => null, 'successor' => null],
        ]);

        $response = $this->filter->after($this->createRequest('/api/v1/users'), $this->createResponse());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('2026-09-01', $response->getHeaderLine('Deprecation'));
        $this->assertSame('2027-03-01', $response->getHeaderLine('Sunset'));
        $this->assertSame('</api/v2>; rel="successor-version"', $response->getHeaderLine('Link'));
    }

    public function testV2RequestDoesNotInheritV1Headers(): void
    {
        $this->withApiVersions([
            'v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-09-01', 'sunset_at' => '2027-03-01', 'successor' => 'v2'],
            'v2' => ['status' => 'current', 'deprecated_at' => null, 'sunset_at' => null, 'successor' => null],
        ]);

        $response = $this->filter->after($this->createRequest('/api/v2/users'), $this->createResponse());

        $this->assertFalse($response->hasHeader('Deprecation'));
        $this->assertFalse($response->hasHeader('Sunset'));
        $this->assertFalse($response->hasHeader('Link'));
    }

    public function testNonApiPathPassesThroughUntouched(): void
    {
        $this->withApiVersions([
            'v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-09-01', 'sunset_at' => '2027-03-01', 'successor' => 'v2'],
        ]);

        // /health, /, /docs etc. should never get version headers.
        $response = $this->filter->after($this->createRequest('/health'), $this->createResponse());

        $this->assertFalse($response->hasHeader('Deprecation'));
        $this->assertFalse($response->hasHeader('Sunset'));
        $this->assertFalse($response->hasHeader('Link'));
    }

    public function testUnknownVersionPassesThroughUntouched(): void
    {
        $this->withApiVersions([
            'v1' => ['status' => 'current', 'deprecated_at' => null, 'sunset_at' => null, 'successor' => null],
        ]);

        // Path mentions v9 — not in the config map; filter must not crash, must not emit.
        $response = $this->filter->after($this->createRequest('/api/v9/users'), $this->createResponse());

        $this->assertFalse($response->hasHeader('Deprecation'));
        $this->assertFalse($response->hasHeader('Sunset'));
    }

    public function testBeforeIsNoopAndReturnsRequest(): void
    {
        $request = $this->createRequest('/api/v1/users');

        $result = $this->filter->before($request);

        $this->assertSame($request, $result);
    }
}
