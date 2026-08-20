<?php

declare(strict_types=1);

namespace App\Filters;

use App\Support\RequestTelemetry;
use CodeIgniter\Events\Events;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Attaches bounded SQL-count telemetry to the global BFF request record. */
final class PublicReadTelemetryFilter implements FilterInterface
{
    /** @var callable|null */
    private static $queryListener = null;

    public function before(RequestInterface $request, $arguments = null): RequestInterface
    {
        self::$queryListener = static function (mixed ...$unused): void {
            unset($unused);
            RequestTelemetry::recordQuery();
        };
        Events::on('DBQuery', self::$queryListener);

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        if (is_callable(self::$queryListener)) {
            Events::removeListener('DBQuery', self::$queryListener);
            self::$queryListener = null;
        }

        return $response;
    }
}
