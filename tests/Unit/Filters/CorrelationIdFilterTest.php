<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use dcardenasl\Ci4ApiCore\Http\Filters\CorrelationIdFilter;
use dcardenasl\Ci4ApiCore\Http\RequestIdHolder;

/**
 * Audit B10.1 (2026-05-07): pin the correlation-id behavior so the
 * cross-service tracing contract doesn't drift.
 *
 * @internal
 */
final class CorrelationIdFilterTest extends CIUnitTestCase
{
    private CorrelationIdFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new CorrelationIdFilter();
        RequestIdHolder::flush();
    }

    protected function tearDown(): void
    {
        RequestIdHolder::flush();
        parent::tearDown();
    }

    private function makeRequest(string $headerValue = ''): IncomingRequest
    {
        $request = new IncomingRequest(
            new AppConfig(),
            new URI('http://localhost/test'),
            null,
            new UserAgent()
        );
        if ($headerValue !== '') {
            $request->setHeader('X-Request-ID', $headerValue);
        }

        return $request;
    }

    public function testBeforeReusesIncomingHeaderWhenWellFormed(): void
    {
        $incoming = '11111111-2222-3333-4444-555555555555';

        $this->filter->before($this->makeRequest($incoming));

        $this->assertSame($incoming, RequestIdHolder::get());
    }

    public function testBeforeGeneratesUuidWhenHeaderMissing(): void
    {
        $this->filter->before($this->makeRequest());

        $generated = RequestIdHolder::get();
        $this->assertIsString($generated);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $generated,
            'Generated ID must be a UUID v4.'
        );
    }

    public function testBeforeGeneratesUuidWhenHeaderTooShort(): void
    {
        $this->filter->before($this->makeRequest('short'));

        $generated = RequestIdHolder::get();
        $this->assertNotSame('short', $generated);
        $this->assertIsString($generated);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $generated);
    }

    public function testBeforeRejectsHeaderWithDisallowedCharacters(): void
    {
        // Spaces are not in the allowed alphabet — must be replaced with
        // a generated UUID. CRLF is impossible here because CI4's
        // IncomingRequest::setHeader validates per RFC 7230 before we
        // ever see it; the filter is a defense-in-depth layer for
        // headers received via PHP-FPM directly.
        $bad = 'evil header with spaces and = signs';

        $this->filter->before($this->makeRequest($bad));

        $generated = RequestIdHolder::get();
        $this->assertNotSame($bad, $generated, 'Filter must reject malformed header.');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $generated);
    }

    public function testBeforeRejectsHeaderLongerThan128Chars(): void
    {
        $bad = str_repeat('a', 129);

        $this->filter->before($this->makeRequest($bad));

        $generated = RequestIdHolder::get();
        $this->assertNotSame($bad, $generated, 'Filter must enforce 128-char ceiling.');
    }

    public function testAfterEmitsResponseHeaderMatchingHolder(): void
    {
        RequestIdHolder::set('abc123-test-correlation');

        $request = $this->makeRequest();
        $response = new Response(new AppConfig());

        $result = $this->filter->after($request, $response);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('abc123-test-correlation', $result->getHeaderLine('X-Request-ID'));
    }

    public function testAfterDoesNotEmitWhenHolderEmpty(): void
    {
        RequestIdHolder::flush();

        $request = $this->makeRequest();
        $response = new Response(new AppConfig());

        $result = $this->filter->after($request, $response);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertFalse($result->hasHeader('X-Request-ID'));
    }
}
