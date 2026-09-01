<?php

declare(strict_types=1);

namespace App\Filters;

use App\Support\RequestTelemetry;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use dcardenasl\Ci4ApiCore\Http\RequestIdHolder;

/** Emits one bounded, structured observation for every BFF request. */
final class RequestTelemetryFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): RequestInterface
    {
        RequestTelemetry::begin(
            RequestIdHolder::get() ?? $request->getHeaderLine('X-Request-ID'),
        );

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        $sources = RequestTelemetry::sourceSummary();
        $cache = RequestTelemetry::cacheSummary();
        $status = $response->getStatusCode();
        $payload = [
            'component' => 'teatromuseo-bff',
            'event' => 'bff_request',
            'request_id' => RequestTelemetry::requestId(),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'seam' => $this->seamFor($request->getUri()->getPath()),
            'duration_ms' => round(RequestTelemetry::elapsedMilliseconds(), 2),
            'status' => $status,
            'response_bytes' => strlen((string) $response->getBody()),
            'db_query_count' => RequestTelemetry::queryCount(),
            'source_count' => $sources['count'],
            'source_duration_ms' => $sources['duration_ms'],
            'source_states' => $sources['states'],
            'source_events' => $sources['events'],
            'cache_hits' => $cache['hit'],
            'cache_misses' => $cache['miss'],
            'cache_bypasses' => $cache['bypass'],
            'cache_stale' => $cache['stale'],
        ];

        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
        log_message(
            $level,
            '[bff-request] ' . (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        RequestTelemetry::reset();

        return $response;
    }

    private function seamFor(string $path): string
    {
        if (str_starts_with($path, '/api/v1/me/admin-')) {
            return 'admin-read';
        }
        if (str_starts_with($path, '/api/v1/public-read/')) {
            return 'public-read';
        }

        return 'proxy';
    }
}
