<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\BaseProxyController;
use App\Support\RequestTelemetry;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

final class BaseProxyControllerTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::reset();
    }

    public function testAggregatePartialReturnsAllSuccessfulSources(): void
    {
        $body = $this->runAggregatePartial([
            'hub' => static fn (): array => ['users' => 4],
            'cms' => static fn (): array => ['pages' => 7],
        ]);

        $this->assertSame('success', $body['status']);
        $this->assertSame(['state' => 'ok', 'data' => ['users' => 4]], $body['data']['hub']);
        $this->assertSame(['state' => 'ok', 'data' => ['pages' => 7]], $body['data']['cms']);
    }

    public function testAggregatePartialMarksAllFailedSourcesUnavailable(): void
    {
        $body = $this->runAggregatePartial([
            'hub' => static function (): array {
                throw new RuntimeException('hub unavailable');
            },
            'cms' => static function (): array {
                throw new RuntimeException('cms unavailable');
            },
        ]);

        $this->assertSame('success', $body['status']);
        $this->assertSame(['state' => 'unavailable', 'data' => []], $body['data']['hub']);
        $this->assertSame(['state' => 'unavailable', 'data' => []], $body['data']['cms']);
    }

    public function testAggregatePartialContinuesAfterOneSourceFails(): void
    {
        $order = [];
        $body  = $this->runAggregatePartial([
            'hub' => static function () use (&$order): array {
                $order[] = 'hub';

                return ['users' => 4];
            },
            'cms' => static function () use (&$order): array {
                $order[] = 'cms';
                throw new RuntimeException('cms unavailable');
            },
            'event' => static function () use (&$order): array {
                $order[] = 'event';

                return ['events' => 2];
            },
        ]);

        $this->assertSame(['hub', 'cms', 'event'], $order);
        $this->assertSame(['state' => 'ok', 'data' => ['users' => 4]], $body['data']['hub']);
        $this->assertSame(['state' => 'unavailable', 'data' => []], $body['data']['cms']);
        $this->assertSame(['state' => 'ok', 'data' => ['events' => 2]], $body['data']['event']);
    }

    public function testAggregatePartialRecordsSourceOutcomesWhenTelemetryIsActive(): void
    {
        RequestTelemetry::begin('request-789');

        $this->runAggregatePartial([
            'hub' => static fn (): array => ['users' => 4],
            'cms' => static function (): array {
                throw new RuntimeException('cms unavailable');
            },
        ]);

        $summary = RequestTelemetry::sourceSummary();
        RequestTelemetry::reset();

        $this->assertSame(2, $summary['count']);
        $this->assertSame(['ok' => 1, 'unavailable' => 1], $summary['states']);
        $this->assertSame('hub', $summary['events'][0]['source']);
        $this->assertSame('unavailable', $summary['events'][1]['state']);
    }

    /**
     * @param array<string, callable(): array<string, mixed>> $calls
     * @return array<string, mixed>
     */
    private function runAggregatePartial(array $calls): array
    {
        $controller = new TestableBaseProxyController();
        $controller->initController(Services::request(), Services::response(), Services::logger());
        $response = $controller->runAggregatePartial($calls);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}

final class TestableBaseProxyController extends BaseProxyController
{
    /**
     * @param array<string, callable(): array<string, mixed>> $calls
     */
    public function runAggregatePartial(array $calls): ResponseInterface
    {
        return $this->aggregatePartial($calls);
    }
}
