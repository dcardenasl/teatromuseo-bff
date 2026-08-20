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

    public function testAggregatePartialDataReturnsAllSuccessfulSources(): void
    {
        $data = $this->runAggregatePartialData([
            'hub' => static fn (): array => ['users' => 4],
            'cms' => static fn (): array => ['pages' => 7],
        ]);

        $this->assertSame('ok', $data['hub']['state']);
        $this->assertSame(['users' => 4], $data['hub']['data']);
        $this->assertIsFloat($data['hub']['duration_ms']);
        $this->assertSame('ok', $data['cms']['state']);
        $this->assertSame(['pages' => 7], $data['cms']['data']);
    }

    public function testAggregatePartialDataMarksAllFailedSourcesUnavailable(): void
    {
        $data = $this->runAggregatePartialData([
            'hub' => static function (): array {
                throw new RuntimeException('hub unavailable');
            },
            'cms' => static function (): array {
                throw new RuntimeException('cms unavailable');
            },
        ]);

        $this->assertSame('unavailable', $data['hub']['state']);
        $this->assertSame([], $data['hub']['data']);
        $this->assertIsFloat($data['hub']['duration_ms']);
        $this->assertSame('unavailable', $data['cms']['state']);
        $this->assertSame([], $data['cms']['data']);
    }

    public function testAggregatePartialDataContinuesAfterOneSourceFails(): void
    {
        $order = [];
        $data  = $this->runAggregatePartialData([
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
        $this->assertSame('ok', $data['hub']['state']);
        $this->assertSame(['users' => 4], $data['hub']['data']);
        $this->assertIsFloat($data['hub']['duration_ms']);
        $this->assertSame('unavailable', $data['cms']['state']);
        $this->assertSame([], $data['cms']['data']);
        $this->assertIsFloat($data['cms']['duration_ms']);
        $this->assertSame('ok', $data['event']['state']);
        $this->assertSame(['events' => 2], $data['event']['data']);
        $this->assertIsFloat($data['event']['duration_ms']);
    }

    public function testAggregatePartialDataRecordsSourceOutcomesWhenTelemetryIsActive(): void
    {
        RequestTelemetry::begin('request-789');

        $this->runAggregatePartialData([
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
     * Regression: `aggregate()`'s outer catch used to only catch `ApiException`,
     * so a plain `Throwable` thrown inside a call closure (e.g. a `RuntimeException`
     * from an `AdminRead`/`PublicRead` source, which never throws `ApiException`)
     * would escape uncaught and fall through to the framework's global exception
     * handler — which, before the `AppExceptionHandler` fix, leaked the raw
     * message to the client outside `development`. `handleOperation()` and
     * `aggregatePartialData()` already caught `Throwable`; this closes the same
     * gap in `aggregate()`.
     */
    public function testAggregateSanitizesAPlainThrowableInsteadOfLettingItEscape(): void
    {
        $controller = new TestableBaseProxyController();
        $controller->initController(Services::request(), Services::response(), Services::logger());

        $response = $controller->runAggregate([
            'hub' => static function (): array {
                throw new RuntimeException('SQLSTATE[42S02]: table `cms_languages` not found at /var/www/app/Foo.php:123');
            },
        ]);

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('cms_languages', json_encode($body, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('/var/www/app/Foo.php', json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, callable(): array<string, mixed>> $calls
     * @return array<string, array{state: 'ok'|'unavailable', data: array<string, mixed>, duration_ms: float}>
     */
    private function runAggregatePartialData(array $calls): array
    {
        $controller = new TestableBaseProxyController();
        $controller->initController(Services::request(), Services::response(), Services::logger());

        return $controller->runAggregatePartialData($calls);
    }
}

final class TestableBaseProxyController extends BaseProxyController
{
    /**
     * @param array<string, callable(): array<string, mixed>> $calls
     * @return array<string, array{state: 'ok'|'unavailable', data: array<string, mixed>, duration_ms: float}>
     */
    public function runAggregatePartialData(array $calls): array
    {
        return $this->aggregatePartialData($calls);
    }

    /**
     * @param array<string, callable(): array<string, mixed>> $calls
     */
    public function runAggregate(array $calls): ResponseInterface
    {
        return $this->aggregate($calls);
    }
}
