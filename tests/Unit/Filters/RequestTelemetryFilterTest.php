<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Filters\RequestTelemetryFilter;
use App\Support\RequestTelemetry;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use dcardenasl\Ci4ApiCore\Http\RequestIdHolder;

final class RequestTelemetryFilterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestTelemetry::reset();
        RequestIdHolder::flush();
    }

    protected function tearDown(): void
    {
        RequestTelemetry::reset();
        RequestIdHolder::flush();
        parent::tearDown();
    }

    public function testBeforeStartsContextAndAfterResetsIt(): void
    {
        $request = new IncomingRequest(
            new AppConfig(),
            new URI('http://localhost/api/v1/me/admin-dashboard'),
            null,
            new UserAgent(),
        );
        RequestIdHolder::set('request-456');
        $response = new Response(new AppConfig());
        $filter = new RequestTelemetryFilter();

        $filter->before($request);
        RequestTelemetry::recordSource('dashboard', 1.0, 'ok', 200);
        $filter->after($request, $response);

        $this->assertNull(RequestTelemetry::requestId());
        $this->assertSame(0, RequestTelemetry::sourceSummary()['count']);
    }
}
