<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Http\Filters\SecurityHeadersFilter;

/**
 * SecurityHeadersFilter Unit Tests
 *
 * Tests that security headers are properly added to responses.
 * Critical for protecting against XSS, clickjacking, MIME sniffing, etc.
 */
class SecurityHeadersFilterTest extends CIUnitTestCase
{
    protected SecurityHeadersFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new SecurityHeadersFilter();
    }

    /**
     * Helper: Create mock request with given URI path
     */
    private function createMockRequest(string $uriPath = '/'): IncomingRequest
    {
        $request = $this->createMock(IncomingRequest::class);
        $uri = $this->createMock(URI::class);

        $uri->method('getPath')
            ->willReturn($uriPath);

        $request->method('getUri')
            ->willReturn($uri);

        return $request;
    }

    /**
     * Helper: Create real Response object
     */
    private function createResponse(): Response
    {
        $config = new \Config\App();

        return new Response($config);
    }

    // ==================== TEST CASES ====================

    public function testAfterAddsXContentTypeOptionsNosniff(): void
    {
        $request = $this->createMockRequest('/test');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        $this->assertTrue($result->hasHeader('X-Content-Type-Options'));
        $this->assertEquals('nosniff', $result->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAfterAddsXFrameOptionsDeny(): void
    {
        $request = $this->createMockRequest('/test');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        $this->assertTrue($result->hasHeader('X-Frame-Options'));
        $this->assertEquals('DENY', $result->getHeaderLine('X-Frame-Options'));
    }

    public function testAfterAddsXXSSProtection(): void
    {
        $request = $this->createMockRequest('/test');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        $this->assertTrue($result->hasHeader('X-XSS-Protection'));
        $this->assertEquals('1; mode=block', $result->getHeaderLine('X-XSS-Protection'));
    }

    public function testAfterAddsReferrerPolicy(): void
    {
        $request = $this->createMockRequest('/test');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        $this->assertTrue($result->hasHeader('Referrer-Policy'));
        $this->assertEquals('strict-origin-when-cross-origin', $result->getHeaderLine('Referrer-Policy'));
    }

    public function testAfterAddsPermissionsPolicy(): void
    {
        $request = $this->createMockRequest('/test');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        $this->assertTrue($result->hasHeader('Permissions-Policy'));
        $this->assertStringContainsString('camera=()', $result->getHeaderLine('Permissions-Policy'));
    }

    public function testAfterAddsCacheControlForApiRoutes(): void
    {
        $request = $this->createMockRequest('/api/v1/users');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        // For API routes, Cache-Control and Pragma headers should be set
        $this->assertTrue($result->hasHeader('Cache-Control'));
        $this->assertTrue($result->hasHeader('Pragma'));
        $this->assertStringContainsString('no-store', $result->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-cache', $result->getHeaderLine('Cache-Control'));
        $this->assertEquals('no-cache', $result->getHeaderLine('Pragma'));
    }

    public function testAfterDoesNotAddCacheControlForNonApiRoutes(): void
    {
        $request = $this->createMockRequest('/health');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        // The filter only adds Pragma header for API routes
        // So for non-API routes, Pragma should not be 'no-cache'
        $pragma = $result->getHeaderLine('Pragma');

        // For non-API routes, Pragma should be empty (not set by the filter)
        $this->assertEmpty($pragma, 'Pragma header should not be set for non-API routes');
    }

    public function testAfterAddsHSTSInProductionOnly(): void
    {
        // Test production environment
        if (ENVIRONMENT === 'production') {
            $request = $this->createMockRequest('/test');
            $response = $this->createResponse();

            $result = $this->filter->after($request, $response);

            $this->assertTrue($result->hasHeader('Strict-Transport-Security'));
            $this->assertStringContainsString('max-age=31536000', $result->getHeaderLine('Strict-Transport-Security'));
            $this->assertStringContainsString('includeSubDomains', $result->getHeaderLine('Strict-Transport-Security'));
        } else {
            $this->markTestSkipped('HSTS test only runs in production environment');
        }
    }

    public function testAfterDoesNotAddHSTSInDevelopment(): void
    {
        // This test verifies HSTS is NOT added in development
        if (ENVIRONMENT !== 'production') {
            $request = $this->createMockRequest('/test');
            $response = $this->createResponse();

            $result = $this->filter->after($request, $response);

            $this->assertFalse($result->hasHeader('Strict-Transport-Security'));
        } else {
            $this->markTestSkipped('Non-production test skipped in production environment');
        }
    }

    public function testBeforeDoesNothing(): void
    {
        $request = $this->createMockRequest('/test');

        $result = $this->filter->before($request);

        $this->assertInstanceOf(\CodeIgniter\HTTP\RequestInterface::class, $result);
    }

    public function testAfterReturnsResponseInstance(): void
    {
        $request = $this->createMockRequest('/test');
        $response = $this->createResponse();

        $result = $this->filter->after($request, $response);

        $this->assertInstanceOf(\CodeIgniter\HTTP\ResponseInterface::class, $result);
    }
}
