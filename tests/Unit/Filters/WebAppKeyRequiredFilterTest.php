<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Filters\WebAppKeyRequiredFilter;
use App\Support\PublicReadCallerContext;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

final class WebAppKeyRequiredFilterTest extends CIUnitTestCase
{
    private WebAppKeyRequiredFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new WebAppKeyRequiredFilter();
        $this->setKey('BFF_API_KEY', 'web-key');
        $this->setKey('WEB_API_KEY', null);
        $this->setKey('TOTEM_BFF_API_KEY', 'totem-key');
    }

    protected function tearDown(): void
    {
        $this->setKey('BFF_API_KEY', null);
        $this->setKey('WEB_API_KEY', null);
        $this->setKey('TOTEM_BFF_API_KEY', null);
        PublicReadCallerContext::flush();
        parent::tearDown();
    }

    /**
     * `env()` reads `$_ENV`/`$_SERVER` before falling back to `getenv()`,
     * and the `.env` file already populates `$_ENV` at bootstrap — a bare
     * `putenv()` in a test is silently shadowed by that. Set/clear all
     * three so the filter under test actually observes the override.
     */
    private function setKey(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public function testWebKeyIsAcceptedAndTagsTheCallerAsWeb(): void
    {
        $result = $this->filter->before($this->requestWithKey('web-key'));

        self::assertNull($result);
        self::assertSame('web', PublicReadCallerContext::get());
    }

    public function testTotemKeyIsAcceptedAndTagsTheCallerAsTotem(): void
    {
        $result = $this->filter->before($this->requestWithKey('totem-key'));

        self::assertNull($result);
        self::assertTrue(PublicReadCallerContext::isTotem());
    }

    public function testUnknownKeyIsRejected(): void
    {
        $result = $this->filter->before($this->requestWithKey('someone-elses-key'));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(401, $result->getStatusCode());
        self::assertNull(PublicReadCallerContext::get());
    }

    public function testMissingKeyIsRejected(): void
    {
        $result = $this->filter->before($this->requestWithKey(''));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(401, $result->getStatusCode());
    }

    public function testFailsClosedWhenNoCallerKeyIsConfiguredAtAll(): void
    {
        $this->setKey('BFF_API_KEY', null);
        $this->setKey('TOTEM_BFF_API_KEY', null);

        $result = $this->filter->before($this->requestWithKey('web-key'));

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function testAfterFlushesTheCallerContext(): void
    {
        $this->filter->before($this->requestWithKey('totem-key'));
        self::assertTrue(PublicReadCallerContext::isTotem());

        $request = $this->requestWithKey('totem-key');
        $response = \Config\Services::response();
        $this->filter->after($request, $response);

        self::assertNull(PublicReadCallerContext::get());
    }

    private function requestWithKey(string $key): IncomingRequest
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getHeaderLine')->with('X-App-Key')->willReturn($key);

        return $request;
    }
}
