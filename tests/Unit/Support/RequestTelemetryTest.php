<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RequestTelemetry;
use CodeIgniter\Test\CIUnitTestCase;

final class RequestTelemetryTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestTelemetry::reset();
    }

    protected function tearDown(): void
    {
        RequestTelemetry::reset();
        parent::tearDown();
    }

    public function testItAggregatesSourcesAndCacheStatesWithoutPayloads(): void
    {
        RequestTelemetry::begin('request-123');
        RequestTelemetry::recordSource('admin.cms.workspace', 2.345, 'ok', 200);
        RequestTelemetry::recordSource('admin.event.lookup', 1.25, 'unavailable', 503);
        RequestTelemetry::recordCache('admin.cms.workspace.languages', 'hit');
        RequestTelemetry::recordCache('admin.cms.workspace.block-types', 'miss');

        $sources = RequestTelemetry::sourceSummary();

        $this->assertSame('request-123', RequestTelemetry::requestId());
        $this->assertSame(2, $sources['count']);
        $this->assertSame(3.6, $sources['duration_ms']);
        $this->assertSame(['ok' => 1, 'unavailable' => 1], $sources['states']);
        $this->assertSame('admin.cms.workspace', $sources['events'][0]['source']);
        $this->assertSame(['hit' => 1, 'miss' => 1, 'bypass' => 0, 'stale' => 0], RequestTelemetry::cacheSummary());
    }

    public function testRecordsNothingBeforeRequestBegins(): void
    {
        RequestTelemetry::recordSource('ignored', 1.0, 'ok', 200);
        RequestTelemetry::recordCache('ignored', 'hit');

        $this->assertSame(0, RequestTelemetry::sourceSummary()['count']);
        $this->assertSame(0, RequestTelemetry::cacheSummary()['hit']);
    }
}
