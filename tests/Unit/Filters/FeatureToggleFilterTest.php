<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use dcardenasl\Ci4ApiCore\Http\Filters\FeatureToggleFilter;

class FeatureToggleFilterTest extends CIUnitTestCase
{
    private FeatureToggleFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new FeatureToggleFilter();
    }

    protected function tearDown(): void
    {
        putenv('METRICS_ENABLED');
        putenv('MONITORING_ENABLED');
        Services::reset(true);
        parent::tearDown();
    }

    public function testBeforeAllowsRequestWhenFeatureIsEnabled(): void
    {
        putenv('METRICS_ENABLED=true');

        $request = $this->createMock(IncomingRequest::class);
        $result = $this->filter->before($request, ['metrics']);

        $this->assertInstanceOf(IncomingRequest::class, $result);
    }

    public function testBeforeBlocksRequestWhenFeatureIsDisabled(): void
    {
        putenv('METRICS_ENABLED=false');

        $request = $this->createMock(IncomingRequest::class);
        $result = $this->filter->before($request, ['metrics']);

        $this->assertInstanceOf(\CodeIgniter\HTTP\ResponseInterface::class, $result);
        $this->assertSame(503, $result->getStatusCode());
    }

    public function testBeforeWithMissingArgumentAllowsRequest(): void
    {
        $request = $this->createMock(IncomingRequest::class);
        $result = $this->filter->before($request, []);

        $this->assertInstanceOf(IncomingRequest::class, $result);
    }
}
